#!/usr/bin/php
<?php

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

$config_template = realpath($argv[1]);
$repository_root = realpath($argv[2]);
$source_dir = $argv[3];
$elements = explode('/', $source_dir);
$project = array_pop($elements);
$destination_dir = $argv[4];

// If the source_dir is an empty directory, skip it; cvs2git barfs on these.
if (is_empty_dir($source_dir)) {
  git_log("Skipping empty source directory '$source_dir'.");
  exit;
}

// If the target destination dir exists already, remove it.
if (file_exists($destination_dir) && is_dir($destination_dir)) {
  rmdirr($destination_dir);
}

// Create the destination directory.
mkdir($destination_dir);
$destination_dir = realpath($destination_dir);

// Create a temporary directory, and register a clean up.
$cmd = 'mktemp -dt cvs2git-import-' . escapeshellarg($project) . '.XXXXXXXXXX';
$temp_dir = realpath(trim(`$cmd`));
register_shutdown_function('_clean_up_import', $temp_dir);

// Move to the temporary directory.
chdir($temp_dir);

// Prepare and write the option file.
$options = array(
  '#DIR#' => $repository_root . '/' . $source_dir,
);
file_put_contents('./cvs2git.options', strtr(file_get_contents($config_template), $options));

// Start the import process.
git_log("Starting the import process on the '$project' project.", 'INFO');
passthru('cvs2git --options=./cvs2git.options');

// Load the data into git.
git_log("Importing '$project' project data into Git.", 'INFO');
git_invoke('git init', FALSE, $destination_dir);
try {
  git_invoke('cat tmp-cvs2git/git-blob.dat tmp-cvs2git/git-dump.dat | git fast-import --quiet', FALSE, $destination_dir);
}
catch (Exception $e) {
  git_log("Fast-import failed on project '$project' with error '$e'", 'WARN');
}

// Do branch/tag renaming
git_log("Performing branch/tag renaming on '$project' project.", 'INFO');
// For core
if ($project == 'drupal' && array_search('contributions', $elements) === FALSE) { // for core
  $trans_map = array(
    // Then do the full transform. One version for 4-7 and prior...
    '/^(\d)-(\d)$/' => '\1.\2.x',
    // And another for D5 and later
    '/^(\d)$/' => '\1.x',
  );
  convert_project_branches($project, $destination_dir, $trans_map);
}
// For contrib minus sandboxes
else if ($elements[0] == 'contributions' && isset($elements[1]) && $elements[1] != 'sandbox') {
  $trans_map = array(
    // Next, ensure that any "pseudo" branch names are made to follow the official pattern
    '/^(\d(-\d)?)$/' => '\1--1',
    // With the prep done, now do the full transform. One version for 4-7 and prior...
    '/^(\d)-(\d)--(\d+)$/' => '\1.\2.x-\3.x',
    // And another for D5 and later
    '/^(\d)--(\d+)$/' => '\1.x-\2.x',
  );
  convert_project_branches($project, $destination_dir, $trans_map);
}

/*
 * Branch/tag renaming functions ------------------------
 */

/**
 * Convert all of a contrib project's branches to the new naming convention.
 */
function convert_project_branches($project, $destination_dir, $trans_map) {
  $branches = array();
  // Generate a list of all valid branch names, ignoring master
  exec("ls " . escapeshellarg("$destination_dir/refs/heads/") . " | egrep '^DRUPAL-'", $branches);
  if (empty($branches)) {
    // No branches to work with, bail out
    return;
  }
  // Everything needs the initial DRUPAL- stripped out.
  $trans_map = array_merge(array('/^DRUPAL-/' => ''), $trans_map);
  $new_branches = preg_replace(array_keys($trans_map), array_values($trans_map), $branches);
  foreach(array_combine($branches, $new_branches) as $old_name => $new_name) {
    try {
    // Now do the rename itself. -M forces overwriting of branches.
      git_invoke("git branch -M $old_name $new_name", FALSE, $destination_dir);
    }
    catch (Exception $e) {
      // These are failing sometimes, not sure why
      git_log("Branch rename failed on project/branch '$project'/'$old_name' with error '$e'", 'WARN');
    }
  }
}

function convert_contrib_project_tags($project, $destination_dir) {

}

function convert_core_tags($destination_dir) {

}

// ------- Utility functions -----------------------------------------------

function _clean_up_import($dir) {
  git_log("Cleaning up import temp directory $dir.", 'INFO');
  passthru('rm -Rf ' . escapeshellarg($dir));
}
