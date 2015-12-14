# F1 Core

Collection of utilities for Wordpress and PHP.

## Installation

 * Install with composer
 * Or, copy to the Wordpress plugins folder, add the autoload declaration to composer.json, and do
 `composer dump-autoload`

There is no need to activate the plugin since composer will handle the loading.

## Using in a public release plugin

For a public release plugin the utilities should probably be copied manually, moving them to a local namespace.

## WP Utilities

### AdminPageHelper

Helper class for managing a plugin options (either network or site level).

General flow:

 * Create the admin page helper in the Wordpress `wp_loaded` hook
 * Use "addSetting" to register a new setting, passing either a bundled renderer or a custom one