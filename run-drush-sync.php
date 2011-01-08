<?php

$drush = empty($argv[1]) ? 'drush' : $argv[1];
$proc_count = empty($argv[2]) ? 8 : (int) $argv[2];

$raw_dirs = glob('/var/git/stagingrepos/project/*.git');
$dirs = preg_replace('#^/var/git/stagingrepos/project/(.*)\.git$#', '\1', $raw_dirs);

$dirs_num = count($dirs);

$chunks = array_chunk($dirs, $dirs_num / $proc_count);

// Run eight forked subprocesses
$success = TRUE;
$forks = 0;
for ($i = 0; $i < $proc_count; ++$i) {
  $pid = pcntl_fork();

  if ($pid == -1) {
    die("oh noes! no fork!");
  }
  elseif ($pid) {
    // Parent; increment the fork counter.
    $forks++;
  }
  else {
    // Child
    $repos = implode(',', $chunks[$i]);
    // give feedback on list of repos to be imported
    passthru(escapeshellarg($drush) . ' vcapi-parse-logs ' . escapeshellarg($repos));
    exit;
  }
}

// Make sure all process finish, then exit.
while ($forks) {
  $pid = pcntl_wait($status);
  $success &= pcntl_wifstopped($status);
  $forks--;
}

exit(!$success);