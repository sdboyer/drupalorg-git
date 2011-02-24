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

set_time_limit(0); // Ensure we don't time out.

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
$status = 0;
$forks = 0;
$empties = new SplFileObject(dirname(__FILE__) . '/empties', 'w');
$emptylist = array();

// Scrub the output dir, it needs to be clean for errors to be real
$shell_dest = escapeshellarg("$destpath/project");
`rm -rf $shell_dest`;

git_log("\n*****************\nBegin forking import processes\n*****************\n", 'DEBUG');
foreach ($list as $n => $line) {
  if ($forks >= $concurrency) {
    $pid = pcntl_wait($status);
    $status = pcntl_wifstopped($status);
    $forks--;
    if (!empty($status)) {
      break;
    }
  }

  $projectdata = explode(',', $line);

  // Core is stupid, as always. Here's hoping this is one of the last special cases we write for it
  if (!in_array($projectdata[1], array('hostmaster', 'provision', 'drupal'))) {
    if (file_exists("$destpath/project/{$projectdata[1]}.git")) {
      git_log('Crap on a cracker, the target dir already exists!', 'WARN', $projectdata[1]);
      continue;
    }

    if (empty($projectdata[0]) || !is_cvs_dir($srcrepo . '/contributions' . $projectdata[0])) {
      git_log('No CVS source information for project; will spawn an empty repo for it later.', 'INFO', $projectdata[1]);
      $empties->fwrite(sprintf('%s,%d' . PHP_EOL, $projectdata[1], $projectdata[3]));
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
    if (preg_match('/^git:/', $projectdata[0])) {
      git_invoke("git clone --bare $projectdata[0] $destpath/project/{$projectdata[1]}.git", FALSE);
      convert_project_branches($projectdata[1], "$destpath/project/{$projectdata[1]}.git", $rename_patterns['contrib']['branches']);
      convert_project_tags($projectdata[1], "$destpath/project/{$projectdata[1]}.git", $rename_patterns['contrib']['tagmatch'], $rename_patterns['contrib']['tags']);
      exit(0);
    }
    else {
      $success = import_directory($optsfile, $srcrepo, ($projectdata[1] == 'drupal' ? 'drupal' : 'contributions') . $projectdata[0], "$destpath/project/{$projectdata[1]}.git", TRUE);
    }
    exit(empty($success));
  }
  git_log("Finished import #$n\n", 'DEBUG');
}

git_log("\n*****************\nFinished forking import processes, now waiting for all children to complete\n*****************\n", 'DEBUG');
// Make sure all forked children finish.
while ($forks) {
  git_log("Fork count remaining: $forks\n", 'DEBUG');
  pcntl_wait($status);
  $forks--;
}

if (!empty($status)) {
  exit($status);
}

git_log("Empties list:\n" . print_r($emptylist, TRUE), 'DEBUG');

// Now do any necessary cleanup/stripping.
git_log("\n*****************\nBegin forking cleanup processes\n*****************\n", 'DEBUG');
$forks = 0; // Reinit just to be sure
foreach ($list as $n => $line) {
  if ($forks >= $concurrency) {
    $pid = pcntl_wait($status);
    $status = pcntl_wifstopped($status);
    $forks--;
    if (!empty($status)) {
      break;
    }
  }

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
    $success = cleanup_migrated_repo($projectdata[1], "$destpath/project/{$projectdata[1]}.git", TRUE, $projectdata[2] == '1');
    exit(empty($success));
  }
  git_log("Finished cleanup #$n\n", 'DEBUG');
}


// Make sure all forked children finish.
git_log("\n*****************\nFinished forking cleanup processes, now waiting for all children to complete\n*****************\n", 'DEBUG');
while ($forks) {
  git_log("Fork count remaining: $forks\n", 'DEBUG');
  pcntl_wait($status);
  $forks--;
}

exit($status);
