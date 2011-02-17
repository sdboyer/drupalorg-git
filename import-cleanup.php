#!/usr/bin/php
<?php
/**
 * @file
 * Strip translations and keywords from a project.
 */

$options = getopt('ktd:');

// Make sure we get a git directory to cleanup.
if (empty($options['d'])) {
  echo "Cleanup script not passed a directory\n";
  exit(1);
}

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

$destination_dir = realpath($options['d']);
$project = basename($destination_dir);

// Core is different. We can't strip translations from it.
if ($project == 'drupal.git') {
  unset($options['t']);
}

cleanup_migrated_repos($project, $destination_dir, isset($options['k']), isset($options['t']));
