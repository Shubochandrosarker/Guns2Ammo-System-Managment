<?php
/**
 * G2A POS Core intentionally retains data on uninstall.
 *
 * POS, ATF, NICS, bound-book, audit, and related records can be subject to
 * statutory retention requirements. Deleting the plugin must therefore never
 * silently delete tables or options. A separately authorized retention/purge
 * procedure should be performed by the site's compliance administrator.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
