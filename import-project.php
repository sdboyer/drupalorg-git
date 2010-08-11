#!/usr/bin/php
<?php

$config_template = realpath($argv[1]);
$base_dir = realpath($argv[2]);
$module = $argv[3];
$destination_dir = $argv[4];

// Create the destination directory, if it doesn't exists.
@mkdir($destination_dir);
$destination_dir = realpath($destination_dir);

// Create a temporary directory, and register a clean up.
$temp_dir = realpath(trim(`mktemp -d cvs2git-import.XXXXXXXXXX`));
register_shutdown_function('_clean_up', $temp_dir);

// Move to the temporary directory.
chdir($temp_dir);

// Prepare and write the option file.
$options = array(
  '#DIR#' => $base_dir . '/' . $module,
);
file_put_contents('./cvs2git.options', strtr(file_get_contents($config_template), $options));

// Start the import process.
_log("Starting the import process.");
passthru('cvs2git --options=./cvs2git.options');

// Load the data into git.
_log("Importing data into Git.");
putenv('GIT_DIR=' . $destination_dir);
system('git init');
system('cat tmp-cvs2git/git-blob.dat tmp-cvs2git/git-dump.dat | git fast-import');

// ------- Utility functions -----------------------------------------------

function _log($message, $variables = array()) {
  echo date('[H:i:s]') . ' ' . strtr($message, $variables) . "\n";
}

function _clean_up($dir) {
  _log("Cleaning up directory %dir.", array('%dir' => $dir));
  passthru('rm -Rf ' . escapeshellarg($dir));
}
