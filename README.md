Altis Analytics Demo Tools
==========================

This plugin provides a tool for importing semi-randomised historical data for testing and demoing Altis Analytics features.

The plugin should be considered to be in Beta state and is not intended for use in production anywhere.

## Installation & Usage

1. Install the plugin to `wp-content/plugins` or wherever your plugin directory is located.
2. Activate the plugin
3. In the admin area under "Tools" go to the "Analytics Demo" page
4. Click the button to import data for the past 7 days or 14 days

The importer can be run multiple times, new session IDs will be created each time and there is a 40% chance of a new endpoint ID being generated.

This means when looking for recurring visitors vs new you should see roughly a 60/40 split.
