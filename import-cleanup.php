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

putenv('GIT_AUTHOR_EMAIL=tggm@no-reply.drupal.org');
putenv('GIT_AUTHOR_NAME=The Great Git Migration');

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

$destination_dir = realpath($options['d']);
$project = basename($destination_dir);

// Core is different. We can't strip translations from it.
if ($project == 'drupal.git') {
  unset($options['t']);
}

// Create a temporary directory, and register a clean up.
$cmd = 'mktemp -dt cvs2git-import-' . escapeshellarg($project) . '.XXXXXXXXXX';
$temp_dir = realpath(trim(`$cmd`));
register_shutdown_function('_clean_up_import', $temp_dir);

git_invoke("git clone $destination_dir $temp_dir");

try {
  $all_branches = git_invoke("ls " . escapeshellarg("$destination_dir/refs/heads/"));
  $all_branches = array_filter(explode("\n", $all_branches)); // array-ify & remove empties
}
catch (Exception $e) {
  git_log("Branch list retrieval failed with error '$e'.", 'WARN', $project);
}

foreach($all_branches as $name) {
  if ($name != 'master') {
    git_invoke("git checkout -t origin/$name", FALSE, "$temp_dir/.git", $temp_dir);
  }
  else {
    git_invoke("git checkout $name", FALSE, "$temp_dir/.git", $temp_dir);
  }
  // If needed, cleanup keywords.
  if (isset($options['k'])) {
    try {
      strip_cvs_keywords($project, $temp_dir);
    }
    catch (exception $e) {
      git_log("CVS tag removal for branch $name failed with error '$e'", 'WARN', $project);
    }
  }
  // If needed, cleanup translations.
  if (isset($options['t'])) {
    try {
      kill_translations($project, $temp_dir);
    }
    catch (exception $e) {
      git_log("Translation removal for branch $name failed with error '$e'", 'WARN', $project);
    }
  }
}

git_invoke('git push', FALSE, "$temp_dir/.git");

// ------- Utility functions -----------------------------------------------

function strip_cvs_keywords($project, $directory) {
  passthru('./strip-cvs-keywords.py ' . escapeshellarg($directory));

  $commit_message = escapeshellarg("Stripping CVS keywords from $project");
  if (git_invoke('git status --untracked-files=no -sz --', TRUE, "$directory/.git", $directory)) {
    git_invoke("git commit -a -m $commit_message", FALSE, "$directory/.git", $directory);
  }
}

function kill_translations($project, $directory) {

  $directories = git_invoke('find ' . escapeshellarg($directory) . ' -name translations -type d');
  $translations = array_filter(explode("\n", $directories)); // array-ify & remove empties
  $directories = git_invoke('find ' . escapeshellarg($directory) . ' -name po -type d');
  $po = array_filter(explode("\n", $directories)); // array-ify & remove empties

  $directories = array_merge($translations, $po);
  if (!empty($directories)) {
    $commit_message = escapeshellarg("Removing translation directories from $project");
    foreach ($directories as $dir) {
      git_invoke("git rm -r $dir", FALSE, "$directory/.git", $directory);
    }
    git_invoke("git commit -a -m $commit_message", FALSE, "$directory/.git", $directory);
  }
}
