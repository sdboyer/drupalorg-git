<?php

$drush = empty($argv[1]) ? 'drush' : $argv[1];
$proc_count = empty($argv[2]) ? 8 : (int) $argv[2];

$raw_dirs = glob('/var/git/stagingrepos/project/*.git');
$dirs = preg_replace('#^/var/git/stagingrepos/project/(.*)\.git$#', '\1', $raw_dirs);

// Pull out the biggest ones to run in just one process, keep things more even
$biggies = array('drupal');
$dirs = array_diff($dirs, $biggies);

$dirs_num = count($dirs);

$chunks = array_chunk($dirs, $dirs_num / ($proc_count - 1));
array_unshift($chunks, $biggies);

// Run forked subprocesses
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
    $child_list = $chunks[$i];
    $chunk_count = (int) (count($child_list) / 3);
    $child_chunks = empty($chunk_count) ? array($child_list) : array_chunk($child_list, $chunk_count);
    foreach ($child_chunks as $chunk) {
      $repos = implode(',', $chunk);
      passthru(escapeshellcmd($drush . ' vcapi-parse-logs ' . escapeshellarg($repos)));
    }
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