#!/usr/bin/env php
<?php

$concurrency = getenv('C2G_CONCURRENCY');
$concurrency = is_string($concurrency) ? $concurrency : 13;

$srcrepo = getenv('C2G_REPOSITORY');
$srcrepo = is_string($srcrepo) ? $srcrepo : '/var/git/cvsmirror';

$destpath = getenv('C2G_DESTINATION');
$destpath = is_string($destpath) ? $destpath : '/var/git/repositories';

$optsfile = getenv('C2G_CVS2GIT_OPTIONS');
$optsfile = is_string($optsfile) ? $optsfile : dirname(__FILE__) . '/cvs2git-trunk.options';

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

if (!file_exists(dirname(__FILE__) . '/project-migrate-info')) {
  // No source transform file, bail out
  echo 'No project migrate info file, cannot proceed.';
  exit(1);
}

// Format: <srcpath> <dest> <strip translations>
$list = file(dirname(__FILE__) . '/project-migrate-info');

// Run forked subprocesses
$ok = TRUE;
$forks = 0;
$empties = new SplFileObject(dirname(__FILE__) . '/empties', 'w');
$emptylist = array();

// Scrub the output dir, it needs to be clean for errors to be real
$shell_dest = escapeshellarg("$destpath/project");
`rm -rf $shell_dest`;

foreach ($list as $n => $line) {
  // if ($ok && $forks <= $proc_count) {
  if ($forks <= $proc_count) {
    $projectdata = explode(',', $line);

    // Core is stupid, as always. Here's hoping this is one of the last special cases we write for it
    if (!$projectdata[1] == 'drupal') {
      if (file_exists("$destpath/project/{$projectdata[1]}.git")) {
        git_log('Crap on a cracker, the target dir already exists!', 'WARN', $projectdata[1]);
        continue;
      }

      if (empty($projectdata[0]) || !is_cvs_dir($srcpath . '/contributions' . $projectdata[0])) {
        git_log('No CVS source information for project; will spawn an empty repo for it later.', 'INFO', $projectdata[1]);
        $empties->fwrite($projectdata[1] . PHP_EOL);
        $emptylist[] = $n;
        continue;
      }
    }

    // OK, we're ready to proceed. fork it FORK IT GOOD
    $pid = pcntl_fork();

    if ($pid == -1) {
      die("oh noes! no fork!");
    }
    else if ($pid) {
      // Parent; increment fork counter.
      $forks++;
    }
    else {
      $success = import_directory($optsfile, $srcpath, ($projectdata[1] == 'drupal' ? 'drupal' : 'contributions') . $projectdata[0], "$destpath/project/{$projectdata[1]}.git", TRUE);
      exit($success);
    }
  }
  else {
    pcntl_wait($status);
    // $ok &= pcntl_wifstopped($status);
    $forks--;
  }
}

// Make sure all forked children finish.
while ($forks) {
  pcntl_wait($status);
  $forks--;
}

// Now do any necessary cleanup/stripping.
foreach ($list as $n => $line) {
  if ($ok && $forks <= $proc_count) {
    // Skip this step if we know the repo doesn't exist.
    if (in_array($n, $emptylist)) {
      continue;
    }

    $projectdata = explode(',', $line);

    // fork it FORK IT _BETTER_
    $pid = pcntl_fork();

    if ($pid == -1) {
      die("oh noes! no fork!");
    }
    else if ($pid) {
      // Parent; increment fork counter.
      $forks++;
    }
    else {
      cleanup_migrated_repo($projectdata[1], "$destpath/project/{$projectdata[1]}.git", TRUE, $projectdata[2] == '1');
      exit;
    }
  }
  else {
    pcntl_wait($status);
    $ok &= pcntl_wifstopped($status);
    $forks--;
  }
}

exit(!$success);