#!/usr/bin/php
<?php
$base_dir = realpath($argv[1]);
$module_dir = $argv[2];
$destination_dir = realpath($argv[3]);

// Get a list of all relevant branches to be tested
$branches = array();
exec("ls " . escapeshellarg("$destination_dir/.git/refs/heads/") . " | egrep '^(DRUPAL|master)'", $branches);

if (empty($branches)) {
  exit();
}

putenv('GIT_WORK_TREE=' . $destination_dir);
putenv('GIT_DIR=' . $destination_dir . '/.git');
// cvs2git often creates empty (dirty) working copies; ensure this is not the case
system('git reset -q --hard');

// Create a temporary directory, and register a clean up.
$temp_dir = realpath(trim(`mktemp -d difftest-cvsgit.XXXXXXXXXX`));
register_shutdown_function('_clean_up', $temp_dir);

foreach ($branches as $branch) {
  $cvsbranch = $branch == 'master' ? 'HEAD' : $branch;
  $cmd =  "cvs -Q -d" . escapeshellarg($base_dir) . " co -d " . escapeshellarg("$temp_dir/$branch") . ' ';
  $cmd .= '-r ' . escapeshellarg($cvsbranch) . ' ';
  $cmd .= escapeshellarg($module_dir);
  system($cmd);

  exec("git checkout -q $branch");
  $ret = 0;
  system('diff -u -x .git -x CVS -I \$Id -r ' . escapeshellarg($destination_dir) . ' ' . escapeshellarg("$temp_dir/$branch"), $ret);
  if (!empty($ret)) {
    _log('****Git branch %branch is inconsistent with corresponding CVS branch.', array('%branch' => $branch));
  }
}

// Switch working copy back to master branch
system("git checkout -q master");


// ------- Utility functions -----------------------------------------------

function _log($message, $variables = array()) {
  //echo date('[H:i:s]') . ' ' . strtr($message, $variables) . "\n";
  echo strtr($message, $variables) . "\n";
}

function _clean_up($dir) {
  //_log("Cleaning up directory %dir.", array('%dir' => $dir));
  passthru('rm -Rf ' . escapeshellarg($dir));
}
