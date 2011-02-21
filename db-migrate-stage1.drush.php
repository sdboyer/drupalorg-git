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


if (!file_exists(dirname(__FILE__) . '/empties')) {
  git_log('Empties file with empty repo data could not be found, aborting to preserve idempotence.', 'WARN');
  exit(1);
}

$result = db_query('SELECT p.nid, p.uri, c.directory, n.status FROM {project_projects} AS p INNER JOIN {cvs_projects} AS c ON p.nid = c.nid INNER JOIN {node} AS n ON p.nid = n.nid');

$projects = array();
while ($row = db_fetch_object($result)) {
  $projects[] = $row;
}

// Get the repomgr queue up and ready
drupal_queue_include();
$queue = DrupalQueue::get('versioncontrol_repomgr');

// ensure the plugin is loaded
ctools_include('plugins');
ctools_plugin_load_class('versioncontrol', 'vcs_auth', 'account', 'handler');

$gitbackend = versioncontrol_get_backends('git');

// Get the info on empty repos and store it in a useful way
$empties_raw = file(dirname(__FILE__) . '/empties');
$empties = array();
foreach ($empties_raw as $empty) {
  $item = explode(',', trim($empty));
  $empties[(int) $item[1]] = $item[0];
}
unset($empties_raw);

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


// A set of repos to assemble via cloning instead
$cloners = array(
  851266 => 'git://github.com/sdboyer/drupalorg-git.git', // tggm, woot!
  196005 => 'git://git.aegirproject.org/provision.git', // aegir, provision
  195997 => 'git://git.aegirproject.org/hostmaster.git', // aegir, hostmaster
);

$git_basedir = variable_get('drupalorg_git_basedir', '/var/git');
$templatedir = "$git_basedir/templates/built/project";

$repos = array();
foreach ($projects as $project) {
  if (empty($project->nid)) {
    git_log('Project has no nid. This should NOT happen.', 'WARN', $project->uri);
    continue;
  }


  if (!is_dir('/var/git/repositories/project/' . $project->uri . '.git') && empty($empties[$project->nid]) && empty($cloners[$project->nid])) {
    git_log(strtr('Project has a CVS path listed, but no code was migrated into a git repository at the expected target location, !location.', array('!project' => $project->uri, '!location' => 'project/' . $project->uri . '.git')), 'WARN', $project->uri);
    continue;
  }

  git_log('Building a VersioncontrolGitRepository object for the project.', 'INFO', $project->uri);

  $data = array(
    'name' => $project->uri,
    'root' => '/var/git/repositories/project/' . $project->uri . '.git',
    'plugins' => array(
      'auth_handler' => 'account', // We can't rely on the $conf default for this b/c vc_project doesn't respect it
    ),
    'vcs' => 'git',
    'project_nid' => $project->nid,
    'update_method' => 1,
  );

  // Build the repo object
  $repo = $gitbackend->buildEntity('repo', $data);

  // Save it, b/c doing it in the job could cause db deadlocks. Yay fast beanstalk!
  // Also ensure the versioncontrol_project_projects association is up to date
  $repo->save();
  db_merge('versioncontrol_project_projects')
    ->key(array('nid' => $repo->project_nid))
    ->fields(array('repo_id' => $repo->repo_id))
    ->execute();

  // Fetch all the maintainer data.
  $maintainers_result = db_select('cvs_project_maintainers', 'c')
      ->fields('c', array('uid'))
      ->condition('c.nid', $project->nid)
      ->execute();

  if (isset($cloners[$project->nid])) {
    $tgt_dir = "$git_basedir/repositories/project/$project->uri.git";

    $job = array(
      'repository' => $repo,
      'operation' => array(
        'passthru' => array(
          "clone --bare --template $templatedir {$cloners[$project->nid]} $tgt_dir",
        ),
      ),
    );
  }
  else if (isset($empties[$project->nid])) {
    // This is one of our projects missing a repo. Build a good init payload.
    $job = array(
      'repository' => $repo,
      'operation' => array(
        // Create the repo on disk, and attach all the right hooks.
        'create' => array(),
      ),
    );
  }
  else {
    // The more typical case, where the repo was already created by cvs2git
    $job = array(
      'repository' => $repo,
      'operation' => array(
        // We need to properly init the hooks now and the config file, after the
        // translation & keyword stripping commits have been pushed in.
        'reInit' => array(array('hooks', 'config')),
      ),
    );
  }
  // Add shared job ops.

  // Save user auth data.
  $job['operation']['setUserAuthData'] = array($maintainers_result->fetchAll(PDO::FETCH_COLUMN), $auth_data);
  // Set the description with a link to the project page
  $job['operation']['setDescription'] = array('For more information about this repository, visit the project page at ' . url('node/' . $repo->project_nid, array('absolute' => TRUE)));

  // Now handle special-case exceptions, typically filter-branches.
  switch ($project->nid) {
    // ubercart_marketplace
    case 277418:
      $job['operation']['passthru'] = array("filter-branch -f --prune-empty --tree-filter " . escapeshellarg("rm -rf mp_tokens") . " -- --all", TRUE);
      break;

    // adaptive_context
    case 176635:
      $job['operation']['passthru'] = array("filter-branch -f --prune-empty --tree-filter " . escapeshellarg("rm -rf ac_access ac_group jqselect") . " -- --all", TRUE);
      break;

    // ecommerce
    case 5841:
      $job['operation']['passthru'] = array("filter-branch -f --prune-empty --tree-filter " . escapeshellarg("rm -rf contrib/inventorymangement contrib/worldpay") . " -- --all", TRUE);
      break;

    // user_board
    case 471518:
      $job['operation']['passthru'] = array("filter-branch -f --prune-empty --tree-filter " . escapeshellarg("rm -rf user_board_activity user_board_userpoints user_board_views") . " -- --all", TRUE);
      break;

    // idthemes cluster of bullshit
    case 525938:
      $job['operation']['passthru'] = array("filter-branch -f --prune-empty --tree-filter " . escapeshellarg("rm -rf idt001 idt002 idt011 idt012") . " -- --all", TRUE);
      break;
    case 525904:
    case 525938:
    case 526216:
    case 526532:
      $job['operation']['passthru'] = array("filter-branch -f --prune-empty --tree-filter " . escapeshellarg("rm -rf branches") . " -- --all", TRUE);
      break;
  }

  git_log("Enqueuing repomgr job with the following payload:\n" . print_r($job['operation'], TRUE), 'DEBUG', $project->uri);

  if ($queue->createItem($job)) {
    git_log("Successfully enqueued repository initialization job.", 'INFO', $repo->name);
  }
  else {
    git_log("Failed to enqueue repository initialization job.", 'WARN', $repo->name);
  }
}

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
  45996 => 'Vacilando',
);

foreach($result as $record) {
  $git_username = empty($exceptions[$record->uid]) ? $record->cvs_user : $exceptions[$record->uid];
  db_update('users')->fields(array('git_username' => $git_username))
    ->condition('uid', $record->uid)->execute();
}
