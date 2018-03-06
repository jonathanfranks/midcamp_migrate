
CONTENTS OF THIS FILE
---------------------

 * About Drupal
 * Configuration and features
 * Installation profiles
 * Appearance
 * Developing for Drupal
 * More information

ABOUT MIDCAMP_MIGRATE
------------

This repository goes along with my Drupal 8 Migrate talk from Midcamp 2018.

To use the site as it is currently committed, install a new site, then set
your system UUID to the one in this project's configuration:

    drush config-set "system.site" uuid 4fc07e59-108e-48bd-bd91-e9a1a0aea21e

Now you can import the configuration, which will set up the content types used
in the demo.

Enable each custom migration module you'd like to try and run your migrations!
