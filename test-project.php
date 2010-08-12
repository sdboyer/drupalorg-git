#!/usr/bin/php
<?php
$base_dir = realpath($argv[1]);
$module_dir = $argv[2];
$destination_dir = realpath($argv[3]);

// Get a list of all relevant branches to be tested
$branches = array();
exec("ls " . escapeshellarg("$destination_dir/refs/heads/") . " | egrep '^(DRUPAL|master)'", $branches);

if (empty($branches)) {
  exit();
}

putenv('GIT_DIR=' . $destination_dir);

// Create a temporary directory, and register a clean up.
$temp_dir = realpath(trim(`mktemp -d`));
register_shutdown_function('_clean_up', $temp_dir);

foreach ($branches as $branch) {
  $cvsbranch = $branch == 'master' ? 'HEAD' : $branch;
  system("cvs -Q -d" . escapeshellarg($base_dir) . " co -d " . escapeshellarg("$temp_dir/cvs/$branch") . ' -r ' . escapeshellarg($cvsbranch) . ' ' . escapeshellarg($module_dir));

  system('git archive --format tar --prefix ' . escapeshellarg("$temp_dir/git/$branch/") . ' --format tar ' . escapeshellarg($branch) . ' | tar x -P');
  $ret = 0;
  exec('diff -u -x CVS -I \$Id -r ' . escapeshellarg("$temp_dir/git/$branch") . ' ' . escapeshellarg("$temp_dir/cvs/$branch"), $output, $ret);
  if (!empty($ret)) {
    _log('****Git branch %branch is inconsistent with corresponding CVS branch.', array('%branch' => $branch));
    _log(implode("\n", $output));
  }
}

// ------- Utility functions -----------------------------------------------

function _log($message, $variables = array()) {
  //echo date('[H:i:s]') . ' ' . strtr($message, $variables) . "\n";
  echo strtr($message, $variables) . "\n";
}

function _clean_up($dir) {
  //_log("Cleaning up directory %dir.", array('%dir' => $dir));
  passthru('rm -Rf ' . escapeshellarg($dir));
}
