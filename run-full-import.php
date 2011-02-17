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
  exit(1);
}

// <srcpath> <dest> <strip translations>
$list = file(dirname(__FILE__) . '/project-migrate-info');

$list_num = count($list);
$chunks = array_chunk($list, $list_num / ($proc_count));

// Run forked subprocesses
$ok = TRUE;
$forks = 0;
$empties = new SplFileObject(dirname(__FILE__) . '/empties', 'w+');
$emptylist = array();

foreach ($list as $n => $line) {
  // if ($ok && $forks <= $proc_count) {
  if ($forks <= $proc_count) {
    $projectdata = explode(' ', $line);

    if (file_exists("$destpath/project/{$projectdata[1]}.git")) {
      git_log('Crap on a cracker, the target dir already exists!', 'WARN', $projectdata[1]);
      continue;
    }
    if (!is_cvs_dir($srcpath . '/contributions' . $projectdata[0])) {
      git_log('No CVS source information for project; will spawn an empty repo for it later.', 'INFO', $projectdata[1]);
      $empties->fwrite($projectdata[1] . "\n");
      $emptylist[] = $n;
      continue;
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
      $success = import_directory($optsfile, $srcpath, 'contributions' . $projectdata[0], "$destpath/project/{$projectdata[1]}.git", TRUE);
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

    $projectdata = explode(' ', $line);

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