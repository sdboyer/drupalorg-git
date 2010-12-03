#!/usr/bin/php
<?php

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

$destination_dir = realpath($argv[1]);
$project = basename($destination_dir);
$commit_message = escapeshellarg("Striping \$Id\$ tags from $project");

// Create a temporary directory, and register a clean up.
$cmd = 'mktemp -dt cvs2git-import-' . escapeshellarg($project) . '.XXXXXXXXXX';
$temp_dir = realpath(trim(`$cmd`));
register_shutdown_function('_clean_up_import', $temp_dir);

git_invoke("git clone $destination_dir $temp_dir");

passthru('./strip-cvs-id-tags.py ' . escapeshellarg($temp_dir));
git_invoke('git commit ' . $temp_dir . ' -m "' . $commit_message . '"', FALSE, "$temp_dir/.git", $temp_dir);
git_invoke('git push', FALSE, "$temp_dir/.git");

// ------- Utility functions -----------------------------------------------

function _clean_up_import($dir) {
  git_log("Cleaning up import temp directory $dir.", 'DEBUG');
  passthru('rm -Rf ' . escapeshellarg($dir));
}
