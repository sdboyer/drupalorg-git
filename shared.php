<?php

if (!defined('LOGLEVEL')) {
  // Let an environment variable set the log level
  $level = getenv('LOGLEVEL');
  if (is_string($level)) {
    define('LOGLEVEL', (int) $level);
  }
  else {
    // Or default to 'normal'
    define('LOGLEVEL', 3);
  }
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

  git_log('Invoking ' . $command, 'DEBUG');

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

function is_empty_dir($dir){
  $files = @scandir($dir);
  return (!$files || count($files) <= 2);
}

/**
 * Check if directory has any CVS information.
 *
 * This is actually sort of a recursive problem. If any subdirectory has
 * CVS information it can be imported.
 */
function is_cvs_dir($dir) {
  $files = @scandir($dir);

  // If there are no files, fail early.
  if (!$files) {
    return FALSE;
  }

  foreach ($files as $file) {
    $absolute = $dir . '/' . $file;

    // Skip POSIX aliases
    if ($file == '.' || $file == '..') continue;

    if (is_dir($absolute) && $file == 'Attic') {
      return TRUE;
    }
    elseif (strpos($file, ',v') !== FALSE) {
      return TRUE;
    }
    elseif (is_dir($absolute) && is_cvs_dir($absolute)) {
      return TRUE;
    }
  }
  return FALSE;
}

/**
 * Recursively delete a directory on a local filesystem.
 *
 * @param string $path
 *   The path to the directory.
 */
function rmdirr($path) {
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
    $item->isFile() ? unlink($item) : rmdir($item);
  }
  rmdir($path);
}

function git_log($message, $level = 'NORMAL', $project = NULL) {
  $loglevels = array(
    'WARN' => 1,
    'QUIET' => 2,
    'NORMAL' => 3,
    'INFO' => 4,
    'DEBUG' => 5,
  );
  if (LOGLEVEL !== 0 && LOGLEVEL >= $loglevels[$level]) {
    if (isset($project)) {
      echo "[" . date('Y-m-d H:i:s') . "] [$level] [$project] $message\n";
    }
    else {
      echo "[" . date('Y-m-d H:i:s') . "] [$level] $message\n";
    }
  }
}

/**
 * Helper function to import a directory to a git repository.
 */
function import_directory($config, $root, $source, $destination, $wipe = FALSE) {
  $absolute_source_dir = $root . '/' . $source;
  $elements = explode('/', $source);
  $project = array_pop($elements);

  // If the source is an empty directory, skip it; cvs2git barfs on these.
  if (is_empty_dir($absolute_source_dir)) {
    git_log("Skipping empty source directory '$absolute_source_dir'.");
    return FALSE;
  }

  if (!is_cvs_dir($absolute_source_dir)) {
    git_log("Skipping non CVS source directory '$absolute_source_dir'.");
    return FALSE;
  }

  // If the target destination dir exists already, remove it.
  if ($wipe && file_exists($destination) && is_dir($destination)) {
    passthru('rm -Rf ' . escapeshellarg($destination));
  }

  // Create the destination directory.
  $ret = 0;
  passthru('mkdir -p ' . escapeshellarg($destination), $ret);
  if (!empty($ret)) {
    git_log("Failed to create output directory at $destination, project import will not procede.", 'WARN', $project);
    return FALSE;
  }

  // Create a temporary directory, and register a clean up.
  $cmd = 'mktemp -dt cvs2git-import-' . escapeshellarg($project) . '.XXXXXXXXXX';
  $temp_dir = realpath(trim(`$cmd`));
  register_shutdown_function('_clean_up_import', $temp_dir);

  // Move to the temporary directory.
  chdir($temp_dir);

  // Prepare and write the option file.
  $options = array(
    '#DIR#' => $absolute_source_dir,
    '#CSV#' => dirname($config) . '/cvs.csv',
  );
  file_put_contents('./cvs2git.options', strtr(file_get_contents($config), $options));

  // Start the import process.
  git_log("Generating the fast-import dump files.", 'DEBUG', $source);
  try {
    git_invoke('cvs2git --options=./cvs2git.options');
  }
  catch (Exception $e) {
    git_log("cvs2git failed with error '$e'. Terminating import.", 'WARN', $source);
    return FALSE;
  }

  // Load the data into git.
  git_log("Importing project data into Git.", 'DEBUG', $source);
  git_invoke('git init', FALSE, $destination);
  try {
    git_invoke('cat tmp-cvs2git/git-blob.dat tmp-cvs2git/git-dump.dat | git fast-import --quiet', FALSE, $destination);
  }
  catch (Exception $e) {
    git_log("Fast-import failed with error '$e'. Terminating import.", 'WARN', $source);
    return FALSE;
  }

  // Do branch/tag renaming
  git_log("Performing branch/tag renaming.", 'DEBUG', $source);
  // For core
  if ($project == 'drupal' && array_search('contributions', $elements) === FALSE) { // for core
    $trans_map = array(
      // One version for 4-7 and prior...
      '/^(\d)-(\d)$/' => '\1.\2.x',
      // And another for D5 and later
      '/^(\d)$/' => '\1.x',
    );
    convert_project_branches($source, $destination, $trans_map);
    // Now tags.
    $trans_map = array(
      // 4-7 and earlier base transform
      '/^(\d)-(\d)-(\d+)/' => '\1.\2.\3',
      // 5 and later base transform
      '/^(\d)-(\d+)/' => '\1.\2',
    );
    convert_project_tags($source, $destination, '/^DRUPAL-\d(-\d)?-\d+(-(\w+)(-)?(\d+)?)?$/', $trans_map);
  }
  // For contrib, minus sandboxes
  else if ($elements[0] == 'contributions' && isset($elements[1]) && $elements[1] != 'sandbox') {
    // Branches first.
    $trans_map = array(
      // Ensure that any "pseudo" branch names are made to follow the official pattern
      '/^(\d(-\d)?)$/' => '\1--1',
      // With pseudonames converted, do full transform. One version for 4-7 and prior...
      '/^(\d)-(\d)--(\d+)$/' => '\1.\2.x-\3.x',
      // And another for D5 and later
      '/^(\d)--(\d+)$/' => '\1.x-\2.x',
    );
    convert_project_branches($source, $destination, $trans_map);
    // Now tags.
    $trans_map = array(
      // 4-7 and earlier base transform
      '/^(\d)-(\d)--(\d+)-(\d+)/' => '\1.\2.x-\3.\4',
      // 5 and later base transform
      '/^(\d)--(\d+)-(\d+)/' => '\1.x-\2.\3',
    );
    convert_project_tags($source, $destination, '/^DRUPAL-\d(-\d)?--\d+-\d+(-(\w+)(-)?(\d+)?)?$/', $trans_map);
  }

  // We succeeded despite all odds!
  return TRUE;
}

/*
 * Branch/tag renaming functions ------------------------
 */

/**
 * Convert all of a contrib project's branches to the new naming convention.
 */
function convert_project_branches($project, $destination_dir, $trans_map) {
  $all_branches = $branches = array();

  try {
    $all_branches = git_invoke("ls " . escapeshellarg("$destination_dir/refs/heads/"));
    $all_branches = array_filter(explode("\n", $all_branches)); // array-ify & remove empties
  }
  catch (Exception $e) {
    git_log("Branch list retrieval failed with error '$e'.", 'WARN', $project);
  }

  if (empty($all_branches)) {
    // No branches at all, bail out.
    git_log("Project has no branches whatsoever.", 'WARN', $project);
    return;
  }

  // Kill the 'unlabeled' branches generated by cvs2git
  $unlabeleds = preg_grep('/^unlabeled/', $all_branches);
  foreach ($unlabeleds as $branch) {
    git_invoke('git branch -D ' . escapeshellarg($branch), FALSE, $destination_dir);
  }

  // Remove cvs2git junk branches from the list.
  $all_branches = array_diff($all_branches, $unlabeleds);

  // Generate a list of all valid branch names, ignoring master
  $branches = preg_grep('/^DRUPAL-/', $all_branches); // @todo be stricter?

  // Remove existing branches that have already been converted
  if (empty($branches)) {
    // No branches to work with, bail out.
    if (array_search('master', $all_branches) !== FALSE) {
      // Project has only a master branch
      git_log("Project has no conforming branches.", 'INFO', $project);
    }
    else {
      // No non-labelled branches at all. This shouldn't happen; dump the whole list if it does.
      git_log("Project has no conforming branches and no master. Full branch list: " . implode(', ', $all_branches), 'WARN', $project);
    }
    return;
  }

  // Everything needs the initial DRUPAL- stripped out.
  git_log("FULL list of the project's branches: \n" . print_r($all_branches, TRUE), 'DEBUG', $project);
  $trans_map = array_merge(array('/^DRUPAL-/' => ''), $trans_map);
  git_log("Branches in \$branches pre-transform: \n" . print_r($branches, TRUE), 'DEBUG', $project);
  $branchestmp = preg_replace(array_keys($trans_map), array_values($trans_map), $branches);
  git_log("Branches after first transform: \n" . print_r($branchestmp, TRUE), 'DEBUG', $project);
  $branches = array_diff($branches, $branchestmp);
  $new_branches = preg_replace(array_keys($trans_map), array_values($trans_map), $branches);
  git_log("Branches after second transform: \n" . print_r($new_branches, TRUE), 'DEBUG', $project);

  foreach(array_combine($branches, $new_branches) as $old_name => $new_name) {
    try {
      // Now do the rename itself. -M forces overwriting of branches.
      git_invoke("git branch -M $old_name $new_name", FALSE, $destination_dir);
    }
    catch (Exception $e) {
      // These are failing sometimes, not sure why
      git_log("Branch rename failed on branch '$old_name' with error '$e'", 'WARN', $project);
    }
  }
  verify_project_branches($project, $destination_dir, $new_branches);
}

/**
 * Verify that the project contains exactly and only the set of branches we
 * expect it to.
 */
function verify_project_branches($project, $destination_dir, $branches) {
  $all_branches = git_invoke("ls " . escapeshellarg("$destination_dir/refs/heads/"));
  $all_branches = array_filter(explode("\n", $all_branches)); // array-ify & remove empties

  if ($missing = array_diff($branches, $all_branches)) {
    git_log("Project should have the following branches after import, but does not: " . implode(', ', $missing), 'WARN', $project);
  }

  if ($nonconforming_branches = array_diff($all_branches, $branches, array('master'))) { // Ignore master
    git_log("Project has the following nonconforming branches: " . implode(', ', $nonconforming_branches), 'NORMAL', $project);
  }
}

function convert_project_tags($project, $destination_dir, $match, $trans_map) {
  $all_tags = $tags = $new_tags = $nonconforming_tags = array();
  try {
    $all_tags = git_invoke('git tag -l', FALSE, $destination_dir);
    $all_tags = array_filter(explode("\n", $all_tags)); // array-ify & remove empties
  }
  catch (Exception $e) {
    git_log("Tag list retrieval failed with error '$e'", 'WARN', $project);
    return;
  }

  // Convert only tags that match naming conventions
  $tags = preg_grep($match, $all_tags);

  if (empty($tags)) {
    // No conforming tags to work with, bail out.
    $string = empty($all_tags) ? "Project has no tags at all." : "Project has no conforming tags.";
    git_log($string, 'NORMAL', $project);
    return;
  }

  // Everything needs the initial DRUPAL- stripped out.
  git_log("FULL list of the project's tags: \n" . print_r($all_tags, TRUE), 'DEBUG', $project);
  $trans_map = array_merge(array('/^DRUPAL-/' => ''), $trans_map);
  // Have to transform twice to discover tags already converted in previous runs
  git_log("Tags in \$tags pre-transform: \n" . print_r($tags, TRUE), 'DEBUG', $project);
  $tagstmp = preg_replace(array_keys($trans_map), array_values($trans_map), $tags);
  git_log("Tags after first transform: \n" . print_r($tagstmp, TRUE), 'DEBUG', $project);
  $tags = array_diff($tags, $tagstmp);
  $new_tags = preg_replace(array_keys($trans_map), array_values($trans_map), $tags);
  git_log("Tags after second transform: \n" . print_r($new_tags, TRUE), 'DEBUG', $project);

  $tag_list = array_combine($tags, $new_tags);
  foreach ($tag_list as $old_tag => $new_tag) {
    // Lowercase all remaining characters (should be just ALPHA/BETA/RC, etc.)
    $tag_list[$old_tag] = $new_tag = strtolower($new_tag);
    // Add the new tag.
    try {
      git_invoke("git tag -f $new_tag $old_tag", FALSE, $destination_dir);
      git_log("Created new tag '$new_tag' from old tag '$old_tag'", 'INFO', $project);
      //if ($key = array_search($new_tag, $all_tags)) {
        // existing tag - skip the rest, otherwise it'll delete the new one.
        //continue;
      //}
    }
    catch (Exception $e) {
      git_log("Creation of new tag '$new_tag' from old tag '$old_tag' failed with message $e", 'WARN', $project);
    }
    // Delete the old tag.
    try {
      git_invoke("git tag -d $old_tag", FALSE, $destination_dir);
      git_log("Deleted old tag '$old_tag'", 'INFO', $project);
    }
    catch (Exception $e) {
      git_log("Deletion of old tag '$old_tag' in project '$project' failed with message $e", 'WARN', $project);
    }
  }

  git_log("Final tag list: \n" . print_r($tag_list, TRUE), 'DEBUG', $project);

  verify_project_tags($project, $destination_dir, $tag_list);
}

/**
 * Verify that the project contains exactly and only the set of tags we
 * expect it to.
 */
function verify_project_tags($project, $destination_dir, $tags) {
  $all_tags = git_invoke('git tag -l', FALSE, $destination_dir);
  $all_tags = array_filter(explode("\n", $all_tags)); // array-ify & remove empties

  if ($missing = array_diff($tags, $all_tags)) {
    git_log("Project should have the following tags after import, but does not: " . implode(', ', $missing), 'WARN', $project);
  }

  if ($nonconforming_tags = array_diff($all_tags, $tags)) {
    git_log("Project has the following nonconforming tags: " . implode(', ', $nonconforming_tags), 'NORMAL', $project);
  }
}

// ------- Utility functions -----------------------------------------------

function _clean_up_import($dir) {
  git_log("Cleaning up import temp directory $dir.", 'DEBUG');
  passthru('rm -Rf ' . escapeshellarg($dir));
}
