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

# Function to get desktop categories
get_desktop_categories() {
    local categories=()
    local category_files=(
        /usr/share/applications/*.desktop
        /usr/local/share/applications/*.desktop
        "${HOME}"/.local/share/applications/*.desktop
    )
    
    for file in "${category_files[@]}"; do
        if [ -f "$file" ]; then
            while IFS= read -r line; do
                if [[ $line =~ ^Categories=(.+)$ ]]; then
                    IFS=';' read -ra cats <<< "${BASH_REMATCH[1]}"
                    for cat in "${cats[@]}"; do
                        if [ -n "$cat" ]; then
                            categories+=("$cat")
                        fi
                    done
                fi
            done < "$file"
        fi
    done
    
    # Remove duplicates and sort
    printf "%s\n" "${categories[@]}" | sort -u
}

# Function to create symlink with command name checking
create_symlink() {
    local appimage_path="$1"
    local app_name=$(basename "${appimage_path%.*}")
    local default_name=$(echo "$app_name" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]//g')
    
    echo "Suggested command name: $default_name"
    
    while true; do
        read -p "Enter command name (press Enter to use suggested name): " command_name
        if [ -z "$command_name" ]; then
            command_name="$default_name"
        else
            command_name=$(echo "$command_name" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]//g')
        fi
        
        # Check if command exists in PATH
        if command -v "$command_name" >/dev/null 2>&1; then
            existing_path=$(command -v "$command_name")
            echo "Warning: Command '$command_name' already exists at: $existing_path"
            read -p "Would you like to try a different name? (Y/n): " try_again
            if [ "${try_again,,}" != "n" ]; then
                continue
            fi
        fi
        
        symlink_path="/usr/local/bin/$command_name"
        
        # Check if symlink already exists
        if [ -e "$symlink_path" ]; then
            echo "Warning: $symlink_path already exists."
            if [ -L "$symlink_path" ]; then
                target=$(readlink -f "$symlink_path")
                echo "It points to: $target"
            fi
            read -p "Do you want to override it? (y/N): " override
            if [ "${override,,}" != "y" ]; then
                read -p "Would you like to try a different name? (Y/n): " try_again
                if [ "${try_again,,}" != "n" ]; then
                    continue
                fi
                echo "Aborted by user."
                exit 1
            fi
            # Remove existing symlink
            if ! sudo rm "$symlink_path"; then
                echo "Failed to remove existing symlink. Make sure you have sudo privileges."
                exit 1
            fi
        fi
        
        # Create symlink using sudo
        if ! sudo ln -s "$appimage_path" "$symlink_path"; then
            echo "Failed to create symlink. Make sure you have sudo privileges."
            exit 1
        fi
        
        echo "Symlink created: $symlink_path"
        echo "$command_name"
        break
    done
}

# Function to create desktop entry
create_desktop_entry() {
    local appimage_path="$1"
    local appimage_fullpath=$(readlink -f "$appimage_path")
    
    if [ ! -f "$appimage_fullpath" ]; then
        echo "File not found: $appimage_path"
        exit 1
    fi

    # Create symlink first
    local command_name=$(create_symlink "$appimage_fullpath")
    
    local app_name=$(basename "${appimage_fullpath%.*}")
    local desktop_entry_dir="${HOME}/.local/share/applications"
    local desktop_entry_path="${desktop_entry_dir}/$app_name.desktop"
    
    # Extract AppImage
    local tmp_dir=$(mktemp -d)
    echo "Extracting AppImage contents..."
    
    if ! "$appimage_fullpath" --appimage-extract --destdir="$tmp_dir" >/dev/null 2>&1; then
        echo "Failed to extract AppImage contents."
        rm -rf "$tmp_dir"
        exit 1
    fi

    # Select icon
    cd "$tmp_dir/squashfs-root" || exit 1
    local icons=(*.png *.svg *.xpm)
    if [ ${#icons[@]} -eq 0 ]; then
        echo "No icon files found in $(pwd)"
        cd - >/dev/null
        rm -rf "$tmp_dir"
        exit 1
    fi

    local icon_src=$(prompt_selection "Choose an icon:" "${icons[@]}")
    local icon_ext="${icon_src##*.}"
    local icon_dir="${HOME}/.local/share/icons"
    local icon_dst="${icon_dir}/$app_name.$icon_ext"
    
    # Create icon directory if it doesn't exist
    mkdir -p "$icon_dir"
    
    # Copy icon
    if ! cp "$icon_src" "$icon_dst"; then
        echo "Failed to copy icon from $icon_src to $icon_dst"
        cd - >/dev/null
        rm -rf "$tmp_dir"
        exit 1
    fi
    
    cd - >/dev/null
    
    # Get and select category
    readarray -t categories < <(get_desktop_categories)
    local category=$(prompt_selection "Choose a category:" "${categories[@]}")
    
    # Create desktop entry directory if it doesn't exist
    mkdir -p "$desktop_entry_dir"
    
    # Create desktop entry
    cat <<EOT > "$desktop_entry_path"
[Desktop Entry]
Name=$app_name
Exec=$command_name
Icon=$icon_dst
Type=Application
Terminal=false
Categories=$category
EOT

    echo "Desktop entry created: $desktop_entry_path"
    
    # Clean up
    rm -rf "$tmp_dir"
}

# Function to remove desktop entry and related files
remove_desktop_entry() {
    local appimage_path="$1"
    local app_name=$(basename "${appimage_path%.*}")
    local icon_dir="${HOME}/.local/share/icons"
    local desktop_entry_dir="${HOME}/.local/share/applications"
    local desktop_entry_path="${desktop_entry_dir}/$app_name.desktop"

    # Remove symlink if exists
    local default_name=$(echo "$app_name" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]//g')
    local symlink_path="/usr/local/bin/$default_name"
    if [ -L "$symlink_path" ]; then
        if ! sudo rm "$symlink_path"; then
            echo "Failed to remove symlink. Make sure you have sudo privileges."
            exit 1
        fi
        echo "Symlink removed: $symlink_path"
    fi

    # Remove desktop entry
    if [ -f "$desktop_entry_path" ]; then
        if ! rm -f "$desktop_entry_path"; then
            echo "Failed to remove desktop entry: $desktop_entry_path"
            exit 1
        fi
    fi

    # Remove icon(s)
    local icon_pattern="$icon_dir/$app_name.*"
    local icons=($icon_pattern)
    for icon in "${icons[@]}"; do
        if [ -f "$icon" ]; then
            if ! rm -f "$icon"; then
                echo "Failed to remove icon: $icon"
                exit 1
            fi
        fi
    done

    echo "Desktop entry and icon removed for $app_name."
}

# Main execution
if [ $# -eq 0 ]; then
    echo "Usage: $0 <path_to_appimage> [--remove]"
    exit 1
fi

# Check if --remove flag is passed
if [ "$2" == "--remove" ]; then
    remove_desktop_entry "$1"
else
    create_desktop_entry "$1"
fi