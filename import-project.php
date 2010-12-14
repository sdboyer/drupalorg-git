<?php

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

if (count($argv) == 5) {
  $config_template = realpath($argv[1]);
  $repository_root = realpath($argv[2]);
  $source_dir = $argv[3];
  $destination_dir = realpath($argv[4]);

  $success = import_directory($config_template, $repository_root, $source_dir, $destination_dir);
  if (!$success) {
    exit('Failed to import ' . $source_dir);
  }
}
else {
  exit('Incorrect number of arguments provided.');
}
