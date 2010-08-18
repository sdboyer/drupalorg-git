#!/usr/bin/php
<?php

// Load shared functions.
require_once './shared.php';

$config_template = realpath($argv[1]);
$repository_root = realpath($argv[2]);
$source_dir = $argv[3];
$project = array_pop(explode($source_dir));
$destination_dir = $argv[4];

// Create the destination directory, if it doesn't exist.
@mkdir($destination_dir);
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
git_log("Starting the import process on the '$project' project.");
passthru('cvs2git --options=./cvs2git.options');

// Load the data into git.
git_log("Importing '$project' project data into Git.");
putenv('GIT_DIR=' . $destination_dir);
git_invoke('git init', FALSE, $destination_dir);
git_invoke('cat tmp-cvs2git/git-blob.dat tmp-cvs2git/git-dump.dat | git fast-import', FALSE, $destination_dir);

// Branch/tag renaming functions ------------------------

/**
 * Convert all of a contrib project's branches to the new naming convention.
 */
function convert_contrib_project_branches($project, $destination_dir) {
  $branches = array();
  exec("ls " . escapeshellarg("$destination_dir/refs/heads/") . " | egrep '^(DRUPAL|master)'", $branches);
  foreach ($branches as $branch) {
    // @todo do the string transform, then rename the branch
  }
}

function convert_contrib_project_tags($project, $destination_dir) {

}



// ------- Utility functions -----------------------------------------------

function _clean_up_import($dir) {
  git_log("Cleaning up import temp directory %dir.", array('%dir' => $dir));
  passthru('rm -Rf ' . escapeshellarg($dir));
}