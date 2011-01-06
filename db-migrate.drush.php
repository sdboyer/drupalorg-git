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
  $projects[] = $row;
}

// ensure the plugin is loaded
ctools_include('plugins');
ctools_plugin_load_class('versioncontrol', 'vcs_auth', 'account', 'handler');

$gitbackend = versioncontrol_get_backends('git');

$auth_data = array(
  'access' => VersioncontrolAuthHandlerMappedAccounts::ALL,
  'branch_create' => VersioncontrolAuthHandlerMappedAccounts::DENY,
  'branch_update' => VersioncontrolAuthHandlerMappedAccounts::DENY,
  'branch_delete' => VersioncontrolAuthHandlerMappedAccounts::DENY,
  'tag_create' => VersioncontrolAuthHandlerMappedAccounts::DENY,
  'tag_update' => VersioncontrolAuthHandlerMappedAccounts::DENY,
  'tag_delete' => VersioncontrolAuthHandlerMappedAccounts::DENY,
  'per-label' => array()
);

// ensure vc_project's table is empty for a nice, clean insert
db_delete('versioncontrol_project_projects')->execute();
$vc_project_insert = db_insert('versioncontrol_project_projects')
  ->fields(array('nid', 'repo_id'));

$repos = array();
foreach ($projects as $project) {
  if (empty($project->nid)) {
    watchdog('cvsmigration', 'No nid for project "!project". This should NOT happen.', array('!project' => $project->uri), WATCHDOG_ERROR);
    continue;
  }
  $parts = explode('/', trim($project->directory, '/'));
  if (!in_array($parts[0], array('modules', 'themes', 'profiles', 'theme-engines')) && $project->nid != 3060) {
    // If the leading path isn't in one of these places, we skip it. unless it's core.
    continue;
  }
  if (!is_dir('/var/git/stagingrepos/project/' . $parts[1] . '.git')) {
    watchdog('cvsmigration', 'Project !project has a CVS path listed, but no code was migrated into a git repository at the expected target location, !location.', array('!project' => $project->uri, '!location' => 'project/' . $project->directory . '.git'), WATCHDOG_ERROR);
    continue;
  }

  $data = array(
    'name' => $parts[1],
    'root' => '/var/git/stagingrepos/project/' . $parts[1] . '.git',
    'vcs' => 'git',
    'plugins' => array(
      // @TODO Update these with d.o specific plugins
      'auth_handler' => 'account',
      'author_mapper' => 'drupalorg_mapper',
      'committer_mapper' => 'drupalorg_mapper',
    ),
  );

  // Build & insert the repo
  $repo = $gitbackend->buildEntity('repo', $data);
  $repo->save();

  if (empty($repo->repo_id)) {
    watchdog('cvsmigration', 'Repo id not present on the "!repo" repository after save. This should NOT happen.', array('!repo' => $repo->name), WATCHDOG_ERROR);
    continue;
  }

  // enqueue the project values for insertion
  $vc_project_insert->values(array('nid' => $project->nid, 'repo_id' => $repo->repo_id));

  $repos[$repo->repo_id] = $repo;

  // Copy commit access from cvs_project_maintainers to versioncontrol
  $auth_handler = $repo->getAuthHandler();
  $maintainers = db_query('SELECT uid FROM cvs_project_maintainers WHERE nid = %d', $project->nid);
  while ($maintainer = db_fetch_object($maintainers)) {
    $auth_handler->setUserData($maintainer->uid, $auth_data);
  }
  $auth_handler->save();
}

// Insert the record of the all the repos into vc_project's tracking table.
$vc_project_insert->execute();
