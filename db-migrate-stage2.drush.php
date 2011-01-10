<?php
/**
 * @file
 * Perform the second stage of nuts-and-bolts db migration on the d.o db. This
 * includes:
 *
 *  - Updating release node tags
 *
 * This file expects to be executed in a bootstrapped environment, presumably
 * via `drush php-script`.
 */

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

global $rename_patterns;

// for ongoing work purposes - a list of release node nids to ignore.
$ignores = array(
  97982, // relativity 4.7.x-2.x-dev
  749752, // forward 6.x-1.15
  96095, // track HEAD...or 6.x-0.x. wtf.
);

$result = db_query('SELECT p.nid, vp.repo_id FROM {project_projects} AS p INNER JOIN {versioncontrol_project_projects} AS vp ON p.nid = vp.nid');

while ($row = db_fetch_object($result)) {
  $repos = versioncontrol_repository_load_multiple(array($row->repo_id), array(), array('may cache' => FALSE));
  $repo = reset($repos);

  if (empty($repo)) {

  }

  $release_query = db_query('SELECT prn.pid, prn.nid, prn.version, prn.tag, prn.version_extra, ct.branch FROM {project_release_nodes} AS prn LEFT JOIN {cvs_tags} AS ct ON prn.pid = ct.nid AND prn.tag = ct.tag WHERE prn.pid = %d', $row->nid);
  // Ensure no stale data.
  db_query('TRUNCATE TABLE {versioncontrol_release_labels}');
  $insert = db_insert('versioncontrol_release_labels')
    ->fields(array('release_nid', 'label_id', 'project_nid'));
  while ($release_data = db_fetch_object($release_query)) {
    if (in_array($release_data->nid, $ignores)) {
      continue;
    }
    update_release($repo, $release_data, $row->nid == 3060 ? $rename_patterns['core'] : $rename_patterns['contrib'], $insert);
  }
  $insert->execute();
}


function update_release(VersioncontrolGitRepository $repo, $release_data, $patterns, $insert) {
  if ($release_data->branch == 1 || $release_data->tag == 'HEAD') { // HEAD doesn't get an entry in {cvs_tags} as a branch.
    // Special-case HEAD.
    if ($release_data->tag == 'HEAD') {
      // TODO note that if/when we do #994244, this'll get a little more complicated.
      $transformed = 'master';
    }
    else {
      $transformed = strtolower(preg_replace(array_keys($patterns['branches']), array_values($patterns['branches']), $release_data->tag));
    }
    git_log("Transformed CVS branch '$release_data->tag' into git branch '$transformed'", 'INFO', $repo->name);
    $labels = $repo->loadBranches(array(), array('name' => $transformed), array('may cache' => FALSE));
    $label = reset($labels);
  }
  else {
    if (!preg_match($patterns['tagmatch'], $release_data->tag)) {
      git_log("Release tag '$release_data->tag' did not match the acceptable tag pattern - major problem, this MUST be addressed.", 'WARN', $repo->name);
      return;
    }
    $transformed = strtolower(preg_replace(array_keys($patterns['tags']), array_values($patterns['tags']), $release_data->tag));
    git_log("Transformed CVS tag '$release_data->tag' into git tag '$transformed'", 'INFO', $repo->name);
    $labels = $repo->loadTags(array(), array('name' => $transformed), array('may cache' => FALSE));
    $label = reset($labels);
  }

  if (empty($label) || empty($label->label_id)) {
    // No label could be found - big problem.
    git_log("No label found in repository '$repo->name' with name '$transformed'. Major problem.", 'WARN', $repo->name);
    return;
  }

  // Update project release node listings.
  // Comment for dev, since it causes the base dataset to change and makes it impossible to run more than once.
  // db_query("UPDATE {project_release_nodes} SET tag = '%s' WHERE nid = %d", array($label->name, $release_data->nid));
  //
  // Insert data into versioncontrol_release_labels, the equivalent to cvs_tags. REPLACE to make repetition easier.
  $insert->values(array(
    'release_nid' => $release_data->nid,
    'label_id' => $label->label_id,
    'project_nid' => $release_data->pid,
  ));
}
