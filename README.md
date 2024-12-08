# AppImage Desktop Entry Manager

A PHP & Bash(TODO) command-line tool that simplifies the process of integrating AppImage applications into your Linux desktop environment. It automatically creates desktop entries, manages icons, and sets up command-line shortcuts for your AppImage applications.

## Requirements

- PHP 7.0 or higher
- Linux environment with desktop entry support

## Installation

1. Download the script:
```bash
git clone https://github.com/botnetdobbs/appimage-desktop-entry.git
cd appimage-desktop-entry
```

2. Make it executable:
```bash
chmod +x create_desktop_entry.{php,sh}
```

## Usage

### Creating a Desktop Entry

```bash
./create_desktop_entry.php /home/user/AppImages/application.AppImage
# OR using bash version
./create_desktop_entry.sh /home/user/AppImages/application.AppImage
```

The script will:
1. Create a system-wide command shortcut
2. Extract the application icon
3. Let you choose from available desktop categories
4. Create a desktop entry file

### Removing a Desktop Entry

```bash
./create_desktop_entry.php /home/user/AppImages/application.AppImage --remove
# Or using bash version
./create_desktop_entry.sh /home/user/AppImages/application.AppImage --remove
```

This will remove:
- The desktop entry file
- The installed icon
- The command shortcut

## License

MIT