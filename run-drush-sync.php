<?php

$drush = empty($argv[1]) ? 'drush' : $argv[1];
$proc_count = empty($argv[2]) ? 8 : (int) $argv[2];

$raw_dirs = glob('/var/git/stagingrepos/project/*.git');
$dirs = preg_replace('#^/var/git/stagingrepos/project/(.*)\.git$#', '\1', $raw_dirs);

$dirs_num = count($dirs);

$per_process_count = $dirs_num / 8;

// Run eight forked subprocesses
$success = TRUE;
$forks = 0;
for ($i = 0; $i < $proc_count; ++$i) {
  $start = $dirs_num * $i;

  if ($i != ($proc_count - 1)) {
    $this_dirs = array_slice($dirs, $start, $per_process_count);
  }
  else {
    $this_dirs = array_slice($dirs, $start);
  }

  $pid = pcntl_fork();

  if ($pid == -1) {
    die("oh noes! no fork!");
  }
  elseif ($pid) {
    // Parent
    $forks++;

    // If we've run out of headroom, wait for a process to finish.
    if ($forks >= ($proc_count - 1)) {
      $pid = pcntl_wait($status);
      $success &= pcntl_wifstopped($status);
      $forks--;
    }
  }
  else {
    // Child
    $repos = implode(',', $this_dirs);
    passthru(escapeshellarg($drush) . ' vcapi-parse-logs ' . escapeshellarg($repos));
    exit;
  }
}

// Make sure all process finish before exiting.
while ($forks) {
  $pid = pcntl_wait($status);
  $success &= pcntl_wifstopped($status);
  $forks--;
}

exit(!$success);