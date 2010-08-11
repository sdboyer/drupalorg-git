<?php
// $Id$

/**
 * @file
 * Import released projects into "stable" git repositories.
 */

// The URL of the 'all projects' XML feed.
define('PROJECT_LIST_XML', 'http://updates.drupal.org/release-history/project-list/all');

// The pattern of the per-project XML feed.
define('PROJECT_RELEASE_XML', 'http://updates.drupal.org/release-history/%project_name/%project_core');

// The pattern of the per-project repository.
define('REPOSITORY_ROOT', '/var/git/repositories/contributions-stable/%project_name.git');

// The default branch to set as the remote head.
// If it doesn't exist, use the higest available API.
define(PROJECT_HEAD_API, '6.x');

// Debug mode will add additional logging.
define('DEBUG', FALSE);

// Display errors and exceptions.
ini_set('display_errors', TRUE);

function _replace_tokens($string, $tokens) {
  return str_replace(array_keys($tokens), array_values($tokens), $string);
}

function project_list() {
  git_log('Fetching project list.');

  $projects = array();
  $project_list = simplexml_load_file(PROJECT_LIST_XML);
  foreach ($project_list as $project) {
    $projects[(string) $project->short_name] = $project;
    $project->repository = _replace_tokens(REPOSITORY_ROOT, array(
      '%project_name' => (string) $project->short_name,
    ));
  }
  return $projects;
}

function project_releases($project) {
  $all_releases = array();
  if (empty($project->api_versions)) {
    return $all_releases;
  }

  foreach ($project->api_versions->api_version as $api_version) {
    $xml_url = _replace_tokens(PROJECT_RELEASE_XML, array(
      '%project_name' => (string) $project->short_name,
      '%project_core' => (string) $api_version,
    ));

    $releases = simplexml_load_file($xml_url);
    if (empty($releases->releases->release)) {
      continue;
    }

    foreach ($releases->releases->release as $release) {
      if ($release->version_extra == 'dev') {
        // Skip dev releases.
        continue;
      }

      $release = (array) $release;
      $release['api_version'] = $api_version;
      $release['branch'] = $api_version . '-' . $release['version_major'] . '.x';

      $all_releases[$release['version']] = (array) $release;
    }
  }

  // Sort releases.
  uasort($all_releases, '_release_compare');

  return $all_releases;
}

function _release_compare($release1, $release2) {
  foreach (array('api_version', 'version_major', 'version_patch', 'date') as $key) {
    if ($release1[$key] - $release2[$key] != 0) {
      return $release1[$key] - $release2[$key];
    }
  }
  // Undecided, let's say they are equal.
  return 0;
}

function _create_temporary_directory() {
  do {
    $temp_dir = sys_get_temp_dir() . '/git-update-releases-' . uniqid(mt_rand(), TRUE);
    $success = mkdir($temp_dir);
  }
  while (!$success);

  return $temp_dir;
}

function git_get_tags($repository) {
  $tags = array();

  $output = git_invoke('git ls-remote --tags origin', TRUE, $repository);
  foreach (explode("\n", $output) as $tag_spec) {
    if (preg_match('@^([a-z0-9]+)\trefs/tags/(.*)$@', trim($tag_spec), $matches)) {
      list(, $commit_id, $tag_name) = $matches;
      $tags[$tag_name] = $commit_id;
    }
  }
  return $tags;
}

function git_get_branches($repository = NULL) {
  $branches = array();
  
  $output = git_invoke('git ls-remote --heads origin', TRUE, $repository);
  foreach (explode("\n", $output) as $branch_spec) {
    if (preg_match('@^([a-z0-9]+)\trefs/heads/(.*)$@', trim($branch_spec), $matches)) {
      list(, $commit_id, $branch_name) = $matches;
      $branches[$branch_name] = $commit_id;
    }
  }
  return $branches;
}

function git_import_release($project, $release, $parent_branch) {
  git_log('Importing release ' . $release['version']);

  // Move to the correct branch.
  $branches = git_get_branches('.git');
  if (!isset($branches[$parent_branch])) {
    // Create a new (disconnected) branch.
    git_invoke('git symbolic-ref HEAD refs/heads/' . $parent_branch);
    git_invoke('rm .git/index', TRUE);
  }
  else {
    // Switch to the correct branch.
    git_invoke('git branch --track ' . $parent_branch . ' origin/' . $parent_branch, TRUE);
    git_invoke('git checkout ' . $parent_branch);
  }

  // Empty the checkout directory.
  git_invoke('find . -mindepth 1 -maxdepth 1 -not -name .git | xargs -I% rm -Rf %');

  // Now extracts the tarball in place.
  try {
    git_invoke('curl -s --location ' . escapeshellarg($release['download_link']) . ' | tar xz --strip-components=1');
  }
  catch (Exception $e) {
    // The download or extraction failed.
    git_log('Error downloading the release ' . $release['download_link'] . ":\n" . $e->getMessage());
    return FALSE;
  }

  // And commit the resulting work area.
  // The add command will fail if there are no files in the release, which is
  // possible (and happens).
  try {
    git_invoke('git add *');
  }
  catch (Exception $e) {
    git_log('Cannot add files to the release release: ' . $e->getMessage(), 'WARN');
    return FALSE;
  }

  try {
    git_invoke('git status');
  }
  catch (Exception $e) {
    git_log('No files to commit', 'WARN');
    return FALSE;
  }

  $message = $release['name'] . "\n\nSee: " . $release['release_link'] . "\n";

  git_invoke('git commit -a -m ' . escapeshellarg($message), FALSE, NULL, array(
    'GIT_AUTHOR_NAME' => $project->title . ' maintainers',
    'GIT_AUTHOR_EMAIL' => 'http://drupal.org/project/' . $project->short_name,
    'GIT_AUTHOR_DATE' => date('c', $release['date']),
    'GIT_COMMITTER_NAME' => $project->title . ' maintainers',
    'GIT_COMMITTER_EMAIL' => 'http://drupal.org/project/' . $project->short_name,
    'GIT_COMMITTER_DATE' => date('c', $release['date']),
  ));
  git_invoke('git tag ' . $release['version']);

  return TRUE;
}

function git_update_head($project, $releases) {
  $found_prefered_api = FALSE;

  foreach ($releases as $release) {
    if ($release['api_version'] == PROJECT_HEAD_API) {
      $found_prefered_api = TRUE;
    }
    elseif ($found_prefered_api) {
      break;
    }
    $candidate_release = $release;
  }

  git_invoke('git symbolic-ref HEAD refs/heads/' . $candidate_release['branch'], FALSE, $project->repository);
}

function project_synchronize_repositories($project) {
  git_log('Synchronizing project ' . $project->short_name);

  $releases = project_releases($project);

  if (empty($releases)) {
    git_log('Project has no releases, nothing to do.');
    return;
  }

  // Create a temporary directory.
  $temporary_dir = _create_temporary_directory();
  chdir($temporary_dir);

  // Checkout the repository.
  $repository = $project->repository;
  if (!file_exists($repository)) {
    mkdir('repo');
    chdir('repo');
    git_invoke('git init');
    git_invoke('git remote add origin ' . escapeshellarg($repository));
  }
  else {
    git_invoke('git clone ' . escapeshellarg($repository) . ' repo');
    chdir('repo');
  }

  $new_releases = FALSE;
  $tags = git_get_tags('.git');
  foreach ($releases as $release) {
    if (isset($tags[$release['version']]) || empty($release['download_link'])) {
      continue;
    }

    $new_releases |= git_import_release($project, $release, $release['branch']);
  }

  if ($new_releases) {
    git_log('Pushing releases for project ' . $project->short_name);

    if (!file_exists($repository)) {
      mkdir($repository, 0777, TRUE);
      git_invoke('git init', FALSE, $repository);
    }

    // Push the resulting repository.
    git_invoke('git push --all origin');
    git_invoke('git push --tags origin');

    // Update the HEAD branch of the repository.
    git_update_head($project, $releases);
  }

  git_invoke('rm -Rf ' . escapeshellarg($temporary_dir));
}

function git_log($message, $type = 'INFO') {
  echo '[' . date('Y-m-d H:i:s') . '] [' . $type . '] ' . $message . "\n";
}

function git_invoke($command, $fail_safe = FALSE, $repository_path = NULL, $env = NULL) {
  if (!isset($env)) {
    $env = $_ENV;
  }
  if ($repository_path) {
    $env['GIT_DIR'] = $repository_path;
  }

  $descriptor_spec = array(
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );

  if (DEBUG) {
    git_log('Invoking ' . $command, 'DEB');
  }

  $process = proc_open($command, $descriptor_spec, $pipes, NULL, $env);
  if (is_resource($process)) {
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $return_code = proc_close($process);

    if ($return_code != 0 && !$fail_safe) {
      throw new Exception("Invocation of '" . $command . "' failed with return code " . $return_code .": \n" . $stdout . $stderr);
    }

    return $stdout;
  }
}

$all_projects = project_list();

if (count($argv) > 1) {
  $projects = $argv;
  array_shift($projects);
}
else {
  $projects = array_keys($all_projects);
}

foreach ($projects as $project_name) {
  if (!isset($all_projects[$project_name])) {
    git_log("Project " . $project . " doesn't exist.", 'ERR-');
    continue;
  }

  $project = $all_projects[$project_name];

  if ($project->short_name == 'drupal') {
    continue;
  }

  project_synchronize_repositories($project);
}
