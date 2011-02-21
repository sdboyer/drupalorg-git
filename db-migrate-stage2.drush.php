<?php
/**
 * @file
 * Perform the second stage of nuts-and-bolts db migration on the d.o db. This
 * includes:
 *
 *  - Enqueuing jobs to re-init repositories with proper hook directories and
 *    set correct descriptions for gitweb
 *  - Updating release node tags
 *
 * This file expects to be executed in a bootstrapped environment, presumably
 * via `drush php-script`.
 */

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

// Do release node conversion. Yuck.

global $rename_patterns;

// Get the repomgr queue up and ready
drupal_queue_include();
$queue = DrupalQueue::get('versioncontrol_repomgr');

// Retrieve list of nids for which we cannot safely do master renames
$result = db_query("SELECT prn.nid
	FROM {project_release_nodes} AS prn
	INNER JOIN {versioncontrol_project_projects} AS vpp ON vpp.nid = prn.pid
	INNER JOIN {versioncontrol_labels} AS vl ON vpp.repo_id = vl.repo_id AND SUBSTRING_INDEX(prn.version, '-dev', 1) = vl.name
	WHERE prn.tag = 'master' and prn.version != 'master'");

$no_master_transform = array();
while ($row = db_fetch_object($result)) {
  $no_master_transform[] = $row->nid;
}

$result = db_query('SELECT p.nid, vp.repo_id FROM {project_projects} AS p INNER JOIN {versioncontrol_project_projects} AS vp ON p.nid = vp.nid');
// Ensure no stale data.
db_query('TRUNCATE TABLE {versioncontrol_release_labels}');

while ($row = db_fetch_object($result)) {
  unset($label, $transformed);
  $repos = versioncontrol_repository_load_multiple(array($row->repo_id), array(), array('may cache' => FALSE));
  $repo = reset($repos);

  $release_query = db_query('SELECT prn.pid, prn.nid, prn.version, prn.tag, prn.version_extra, ct.branch, n.status, p.uri FROM {project_release_nodes} AS prn INNER JOIN {project_projects} AS p ON prn.pid = p.nid INNER JOIN {node} AS n ON prn.nid = n.nid LEFT JOIN {cvs_tags} AS ct ON prn.pid = ct.nid AND prn.tag = ct.tag WHERE prn.pid = %d', $row->nid);
  $insert = db_insert('versioncontrol_release_labels')
    ->fields(array('release_nid', 'label_id', 'project_nid'));
  while ($release_data = db_fetch_object($release_query)) {
    $vars = array(
      '%nid' => $release_data->nid,
      '%label' => $release_data->tag,
      '%version' => $release_data->version,
    );
    $msg = "Processing release node %nid, tied to label %label and given release version %version";
    git_log(strtr($msg, $vars), 'INFO', $release_data->uri);

    $patterns = $row->nid == 3060 ? $rename_patterns['core'] : $rename_patterns['contrib'];

    if ($release_data->branch == 1 || $release_data->tag == 'HEAD') { // HEAD doesn't get an entry in {cvs_tags} as a branch.
      /*
       * Special-case HEAD. There are ~3275 release nodes pointing to HEAD.
       *
       * There are three ways we handle releases tied to HEAD:
       *
       *  1. When the release version is also HEAD, we just transform both to
       *     master and call it done. There are ~820 release nodes like this which
       *     are basically all defunct, as the HEAD/HEAD pattern hasn't been
       *     allowed for years.
       *
       *  2. When the release version is something other than HEAD, we derive the
       *     appropriate branch name from that version string, rename the branch
       *     accordingly in git and update the release node - iff a branch does
       *     not already exist with that name. There are ~2000 release nodes
       *     without a conflict that get this rename.
       *
       *  3. If there IS a conflict discovered in the logic in #2, we just leave
       *     the release node & git branch as-is, and leave it up to the
       *     maintainer to resolve the problem on their own.
       */
      if ($release_data->tag == 'HEAD') {
        // Case 1 first, do a straight transform
        if ($release_data->version == 'HEAD') {
          $release_data->version = 'master';
          $transformed = 'master';
        }
        // Now case 2, the one we actually like!
        else if (!in_array($release_data->nid, $no_master_transform)) {
          $transformed = substr($release_data->version, 0, -4); // pop -dev off the end
          $arr = $repo->loadBranches(array(), array('name' => 'master'));
          $vc_branch = reset($arr);
          $vc_branch->name = $transformed;
          $vc_branch->save();
          $job = array(
            'repository' => $repo,
            'operation' => array(
              'passthru' => "branch -m master $transformed",
            ),
          );

          if ($queue->createItem($job)) {
            git_log("Successfully enqueued master -> $transformed branch namechange job.", 'INFO', $repo->name);
          }
          else {
            git_log("Failed to enqueue master branch namechange job.", 'WARN', $repo->name);
          }
        }
        // Conflicting branch name, so just change HEAD -> master in the tag
        else {
          $transformed = 'master';
        }
      }
      else {
        // The strtolower is probably redundant now, but oh well
        $transformed = strtolower(preg_replace(array_keys($patterns['branches']), array_values($patterns['branches']), $release_data->tag));
      }

      git_log("Transformed CVS branch '$release_data->tag' into git branch '$transformed'", 'INFO', $repo->name);

      // Don't reload the label if we already have it (if we did a HEAD/master transform)
      if (!empty($label)) {
        $labels = $repo->loadBranches(array(), array('name' => $transformed), array('may cache' => FALSE));
        $label = reset($labels);
      }
    }
    else {
      if (!preg_match($patterns['tagmatch'], $release_data->tag)) {
	if (!empty($release_data->status)) {
	  print_r($release_data);
          git_log("Release tag '$release_data->tag' did not match the acceptable tag pattern - major problem, this MUST be addressed.", 'WARN', $repo->name);
          continue;
        }
        else {
          git_log("Unpublished release tag '$release_data->tag' did not match the acceptable tag pattern. Annoying, but not critical.", 'NORMAL', $repo->name);
          continue;
        }
      }
      $transformed = strtolower(preg_replace(array_keys($patterns['tags']), array_values($patterns['tags']), $release_data->tag));
      git_log("Transformed CVS tag '$release_data->tag' into git tag '$transformed'", 'INFO', $repo->name);

      $labels = $repo->loadTags(array(), array('name' => $transformed), array('may cache' => FALSE));
      $label = reset($labels);
    }

    if (empty($label) || empty($label->label_id)) {
      // No label could be found - big problem if the release node is published, will cause packaging errors.
      if (!empty($release_data->status)) {
	  print_r($release_data);
          git_log("No label found in repository '$repo->name' with name '$transformed'. Major problem.", 'WARN', $repo->name);
          continue;
        }
	else {
	  git_log("No label found in repository '$repo->name' with name '$transformed'. However, release node is unpublished, so just really freakin annoying.", 'QUIET', $repo->name);
          continue;
        }
    }

    // Update project release node listings
    db_query("UPDATE {project_release_nodes} SET tag = '%s', version = '%s' WHERE nid = %d", array($label->name, $release_data->version, $release_data->nid));

    $values = array(
      'release_nid' => $release_data->nid,
      'label_id' => $label->label_id,
      'project_nid' => $release_data->pid,
    );

    git_log("Enqueuing the following release data for insertion into {versioncontrol_release_labels}:\n" . print_r($values, TRUE), 'DEBUG', $repo->name);

    $insert->values($values);
  }
  // Insert data into versioncontrol_release_labels, the equivalent to cvs_tags.
  $insert->execute();
}
