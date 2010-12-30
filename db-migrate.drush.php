<?php
/**
 * @file
 * Perform the nuts-and-bolts db updates for the migration from CVS to git.
 *
 * This file expects to be executed in a bootstrapped environment, presumably
 * via `drush php-script`.
 */

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

$result = db_query('SELECT p.nid, p.uri, c.directory FROM project_projects AS p INNER JOIN cvs_projects AS c ON p.nid = c.nid');

$projects = array();
while ($row = db_fetch_object($result)) {
  $projects[$row->nid] = $row;
}

$gitbackend = versioncontrol_get_backends('git');

$vc_project_insert = db_insert('versioncontrol_project_projects')
  ->fields(array('nid', 'repo_id'));

$repos = array();
foreach ($projects as $project) {
  $parts = explode('/', trim($project->directory, '/'));
  if (!in_array($parts[0], array('modules', 'themes', 'profiles', 'theme-engines')) && $project->nid != 3060) {
    // If the leading path isn't in one of these places, we skip it. unless it's core.
    continue;
  }
  if (!is_dir('/var/git/stagingrepos/project/' . $parts[1] . '.git')) {
    watchdog('cvsmigration', 'Project !project has a CVS path listed, but no code was migrated into a git repository at the expected target location, !location.', array('!project' => $project->uri, '!location' => 'project/' . $project->directory . '.git', WATCHDOG_ERROR));
    continue;
  }

  $data = array(
    'name' => 'project_' . $parts[1],
    'root' => '/var/git/stagingrepos/project/' . $parts[1] . '.git',
    'vcs' => 'git',
  );

  // Build & insert the repo
  $repo = $gitbackend->buildEntity('repo', $data);
  $repo->save();
  // enqueue the project values for insertion
  $vc_project_insert->values(array('nid' => $project->nid, 'repo_id' => $repo->repo_id));

  $repos[$repo->repo_id] = $repo;
}

// Insert the record of the all the repos into vc_project's tracking table.
$vc_project_insert->execute();
