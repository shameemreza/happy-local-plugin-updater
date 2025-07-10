# Happy Local Plugin Updater

A WordPress plugin that allows you to update plugins from your local repository, specifically designed for WordPress/WooCommerce support engineers and developers who need to maintain multiple local testing environments.

## Purpose

This plugin was created for personal use to solve a common challenge faced by WordPress support engineers and developers: keeping multiple local test sites updated with the latest versions of plugins being supported.

**Perfect for:**

- WooCommerce Happiness Engineers maintaining local test environments
- WordPress plugin developers testing compatibility across multiple sites
- Support teams needing to quickly update testing environments
- Anyone who maintains multiple WordPress installations and needs to keep plugins in sync

Instead of manually downloading and installing plugin updates across multiple sites, this plugin allows you to maintain a single local repository of plugin zip files and easily update any WordPress installation from that repository.

## Features

- Update plugins from your local repository with a single click
- Check for available updates with a modern, user-friendly interface
- Schedule automatic update checks (hourly, twice daily, or daily)
- Configure automatic plugin updates for hands-off maintenance
- Debug mode with detailed logging for troubleshooting
- Simple, intuitive interface integrated with WordPress admin

## Installation

1. Clone or download this repository to your computer
2. Copy the `happy-local-plugin-updater` folder to your WordPress plugins directory
3. Activate the plugin through the WordPress admin interface

## Configuration

**IMPORTANT: You must set the repository path before the plugin will work.**

1. Go to Plugins > Happy Updater in the WordPress admin menu
2. Set the path to your local plugin repository (e.g., `/path/to/your/plugin/repository`)
3. Enable automatic update checks if desired
4. Optionally enable automatic updates
5. Enable debug mode if you need detailed logs for troubleshooting
6. Click "Check for Updates" to see available updates

## Repository Structure

Your repository should be structured as follows:

```
/path/to/your/plugin/repository/
├── plugin-folder-name/
│   └── plugin-folder-name.zip
├── another-plugin/
│   └── another-plugin.zip
└── other-plugin/
    └── other-plugin.zip
```

Each plugin should have its own folder matching the plugin's slug, and contain a ZIP file with the same name.

## Usage

### Updating Plugins

There are two ways to update plugins:

1. From the Plugins > Happy Updater page:

   - Click "Check for Updates" to see available updates
   - Click "Update" next to any plugin you want to update

2. From the Plugins page:
   - Look for the "Update from Local" link next to any plugin
   - Click the link to update the plugin from your local repository

### Automatic Updates

If you enable both "Auto Check Updates" and "Auto Update Plugins" in the settings, the plugin will:

1. Automatically check for updates at your chosen frequency (hourly, twice daily, or daily)
2. Automatically install any available updates from your local repository

This is particularly useful for:

- Maintaining multiple test sites with the latest plugin versions
- Ensuring consistency across different development environments
- Automating routine updates to focus on actual testing and development

### Debug Mode

Enable Debug Mode in the plugin settings to:

1. Create detailed logs of update operations in the debug.log file
2. Troubleshoot issues with plugin updates
3. Monitor automatic update activities

## Use Case: WordPress/WooCommerce Support

If you're working in WordPress/WooCommerce support:

1. Maintain a central repository of all plugins you support
2. Use a script to automatically update this repository with the latest versions (from Git or other sources)
3. Install Happy Local Plugin Updater on all your test sites
4. Configure each site to point to your central repository
5. Easily keep all sites updated with the latest versions for testing

This workflow eliminates the need to manually download and install plugin updates across multiple sites, saving significant time and ensuring consistency in your testing environments.

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Local file system access to your WordPress installation
- A properly structured local plugin repository

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Created by Shameem Reza for personal use in local test workflows. Feel free to adapt it to your own needs!
