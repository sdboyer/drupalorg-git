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
  else {
    $name = $parts[1];
  }

  if ($project->nid == 3060) {
    // special-case core.
    $name = 'drupal';
  }

  if (!is_dir('/var/git/stagingrepos/project/' . $name . '.git')) {
    watchdog('cvsmigration', 'Project !project has a CVS path listed, but no code was migrated into a git repository at the expected target location, !location.', array('!project' => $project->uri, '!location' => 'project/' . $name . '.git'), WATCHDOG_ERROR);
    continue;
  }

  $data = array(
    'name' => $name,
    'root' => '/var/git/stagingrepos/project/' . $name . '.git',
    'vcs' => 'git',
    'plugins' => array(
      // @TODO Update these with d.o specific plugins
      'auth_handler' => 'account',
    ),
    'update_method' => 1,
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

// Mark all project nodes as needing to be re-indexed in Solr.
apachesolr_mark_node_type('project_project');

// ------------------
// Perform role & perm-related migration steps.

$git_admin_rid = DRUPALORG_GIT_GATEWAY_ADMIN_RID;
$git_vetted_rid = DRUPALORG_GIT_GATEWAY_VETTED_RID;
$git_user_rid = DRUPALORG_GIT_GATEWAY_RID;
$admin_rid = 3;
$user_admin_rid = 7;

// First do the new git perms.
db_query('DELETE FROM {permission} WHERE rid IN (%d, %d, %d)', array($git_admin_rid, $git_vetted_rid, $git_user_rid));
db_query('DELETE FROM {users_roles} WHERE rid IN (%d, %d, %d) AND uid NOT IN (%d, %d, %d)', array($git_admin_rid, $git_vetted_rid, $git_user_rid, 1118416, 1118412, 1123222));

$perm_insert = db_insert('permission')->fields(array('rid', 'perm', 'tid'));

$git_perms = array(
  $git_user_rid => array(
    'opt-in or out of tracking', 'create images', 'edit own images', 'pift re-test files',
    'use version control systems', 'create sandbox projects',
  ),
  $git_vetted_rid => array(
    'create full projects',
  ),
  $git_admin_rid => array(
    'access site-wide contact form', 'opt-in or out of tracking',
    'administer projects', 'access administration pages', 'access site reports',
    'administer version control systems', 'create full projects',
  ),
);

$perm = db_result(db_query('SELECT perm FROM {permission} WHERE rid = 2'));
$perm = explode(', ', $perm);
// For some reason, auth users are getting the 'create full projects' permission. Crazy. Make sure they don't.
if ($idx = array_search('create full projects', $perm)) {
  unset($perm[$idx]);
  db_query("UPDATE {permission} SET perm = '%s' WHERE rid = 2", implode(', ', $perm));
}

foreach ($git_perms as $rid => $perms) {
  $perm_insert->values(array(
    'rid' => $rid,
    'perm' => implode(', ', $perms),
    'tid' => 0,
  ));
}
$perm_insert->execute();

// Now update existing roles' perms as needed.
$other_perms = array(
  1 => ', access commit messages',
  2 => ', manage own SSH public keys, view own SSH public keys, access commit messages',
  $admin_rid => ', administer SSH public keys, manage any SSH public keys, view any SSH public keys, administer version control systems',
  $user_admin_rid => ', manage any SSH public keys, view any SSH public keys',
);

foreach ($other_perms as $rid => $perms) {
  db_query("UPDATE {permission} SET perm = CONCAT(perm, '%s') WHERE rid = %d", array($perms, $rid));
}

// Now translate exisitng users' perms, as appropriate.
// Give all current CVS users the 'Git vetted user' role. Unfortunately, the 'CVS users' role is unreliable.
db_query("DELETE FROM {users_roles} WHERE rid = 8", $git_vetted_rid);
db_query('INSERT INTO {users_roles} (uid, rid) SELECT uid, %d FROM cvs_accounts WHERE status = 1 ON DUPLICATE KEY UPDATE rid=rid', DRUPALORG_GIT_GATEWAY_VETTED_RID);
db_query('UPDATE {users} SET git_vetted = 1 WHERE uid IN ((SELECT uid FROM {cvs_accounts} WHERE status = 1))');

// Turn CVS administrators into Git administrators.
db_query("UPDATE {users_roles} SET rid = %d WHERE rid = 6", $git_admin_rid);

// Get rid of the old CVS roles.
db_query('DELETE FROM {role} WHERE rid IN (6, 8)');
db_query('DELETE FROM {permission} WHERE rid IN (6, 8)');


// ------------------
/* Transfer CVS usernames over to the new Git username system. */

// Grab all cvs account names from existing approved account list.
$result = db_select('cvs_accounts', 'ca')->fields('ca', array('uid', 'cvs_user'))
  ->condition('status', 1)
  ->distinct()
  ->execute();

// Handle requested alternate usernames from users.
$exceptions = array(
  62496 => 'mikey_p',
  926382 => 'JoshTheGeek',
  920 => 'betarobot',
  384214 => 'nschloe',
  // 35369 => 'svendecabooter',
  16327 => 'instanceofjamie',
  80656 => 'nunoveloso',
  32793 => 'mo6',
  250828 => 'john.karahalis',
  123980 => 'bradweikel',
  25564 => 'twom',
  270434 => 'babbage',
  4299 => 'reyero',
  47085 => 'lourdas_v',
  241634 => 'tim.plunkett',
  26398 => 'Crell',
  24950 => 'bensheldon',
  22079 => 'hunmonk',
  8026 => 'mikehostetler',
  11289 => 'joshuajabbour',
  47135 => 'alonpeer',
  12363 => 'jbrauer',
  20975 => 'agentrickard',
  195353 => 'bensnyder',
  383424 => 'mr.baileys',
  61601 => 'elliotttf',
  22773 => 'chadcf',
  358731 => 'wiifm',
  193303 => 'lelizondo',
  125384 => 'nhck',
  219482 => 'beautifulmind',
  88931 => 'Jacine',
  186696 => 'ademarco',
  322673 => 'baloo',
  207484 => 'mmartinov',
  183956 => 'tobiasb',
  188571 => 'greghines',
  186334 => 'justin',
  6521 => 'benshell',
  163737 => 'corey.aufang',
  143 => 'singularo',
  76026 => 'grobot',
  571032 => 'arshad',
  692532 => 'simme',
  64383 => 'mlncn',
  43205 => 'scb',
  229048 => 'axel.rutz',
  43568 => 'Ralf',
  73919 => 'Magnus',
  690640 => 'danpros',
  216107 => 'alexjarvis',
  54135 => 'levelos',
  68905 => 'mhrabovcin',
  100783 => 'adamdicarlo',
  194674 => 'vinoth.3v',
  123779 => 'macedigital',
  103299 => 'DirkR',
  203750 => 'abraham',
  14475 => 'nsyll',
  384578 => 'aaronbauman',
  130383 => 'greg.harvey',
  240860 => 'tedbow',
  99872 => 'rich.yumul',
  211387 => 'kylebrowning',
  373605 => 'mundanity',
  38806 => 'hadifarnoud',
  185768 => 'aaronfulton',
  221033 => 'jdelaune',
  165089 => 'bob.hinrichs',
  45449 => 'chulkilee',
  132410 => 'tnanek',
  62965 => 'Xano',
  766132 => 'aaronlevy',
  86970 => 'ivan.zugec',
  398572 => 'Chia',
  48898 => 'smk-ka',
  46153 => 'yoran',
  324696 => 'JayMatwichuk',
  509892 => 'jm.federico',
  80902 => 'jdleonard',
  222669 => 'drupal-at-imediasee',
  66894 => 'stella',
  386087 => 'sfreudenberg',
  367108 => 'j_ayen_green',
  97023 => 'CrookedNumber',
  44114 => 'liquidcms',
  103709 => 'nvoyageur',
  76033 => 'gazwal',
  37266 => 'geodaniel',
  297478 => 'coderintherye',
  259320 => 'darrenmothersele',
  665088 => 'tsitaru',
  23728 => 'Sweetchuck',
  37031 => 'dropcube',
  103458 => 'socki',
  66163 => 'floretan',
  228712 => 'skyred',
  310132 => 'Doc',
  226976 => 'ZenDoodles',
  8791 => 'sillygwailo',
  138300 => 'dnotes',
  18535 => 'claudioc',
  191570 => 'smartinm',
  537590 => 'cignex',
  39343 => 'bboyjay',
  41519 => 'stevepurkiss',
  56346 => 'ominds',
  10297 => 'cfennell',
  651550 => 'adr_p',
  106373 => 'wicher',
  112063 => 'frankcarey',
  96826 => 'irakli',
  166383 => 'chrisshattuck',
);

foreach($result as $record) {
  $git_username = empty($exceptions[$record->uid]) ? $record->cvs_user : $exceptions[$record->uid];
  db_update('users')->fields(array('git_username' => $git_username))
    ->condition('uid', $record->uid)->execute();
}
