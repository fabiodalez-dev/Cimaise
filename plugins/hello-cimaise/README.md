# Hello Cimaise Plugin

Simple example plugin demonstrating the Cimaise hooks system.

## Features

- ✅ Logs application initialization
- ✅ Adds custom admin menu item
- ✅ Adds custom settings tab
- ✅ Logs album creation events
- ✅ Adds custom footer message

## Installation

Plugin is auto-loaded from `plugins/` directory. No manual installation needed.

## Usage

After activation:

1. **Admin Menu**: See "Hello Plugin" in admin sidebar
2. **Settings**: Go to Settings → Hello Plugin tab
3. **Frontend**: Custom message appears in footer
4. **Logs**: Check error logs for plugin events

## Hooks Used

- `cimaise_init` - Application initialization
- `admin_menu_items` - Add admin menu
- `settings_tabs` - Add settings tab
- `album_after_create` - Log album creation
- `footer_content` - Modify footer HTML

## Configuration

### Settings Options

**Enable Hello Plugin**
- Type: Checkbox
- Default: `true`
- Description: Master toggle for plugin features

**Welcome Message**
- Type: Text
- Default: `"Powered by Hello Cimaise Plugin!"`
- Description: Custom footer message

**Log Level**
- Type: Select
- Options: `none`, `error`, `info`, `debug`
- Default: `info`
- Description: Logging verbosity

## For Developers

This plugin serves as a **learning example** for:
- Basic plugin structure
- Hook registration patterns
- Filter vs Action usage
- Settings integration
- Error handling

Check the source code for inline comments and explanations.

## License

MIT

## Author

Cimaise Team
