<?php

// Allow the build interval to be passed to the script. If none is provided, we
// default to 5 minutes.
// Must be provided in seconds.
$build_interval = getenv('BUILD_INTERVAL') ? getenv('BUILD_INTERVAL') : '300';
$last_poll = time() - $build_interval;
// Number of threads to allow.
$threads = 8;
// Config template file.
$config_template = realpath('./cvs2git.options');
// Repository locations.
$repository = '/var/git/cvsmirror';
$destination = '/var/git/repositories';

require_once './shared.php';

// Allow paging through the RSS results to prevent commit overuns.
$page = 0;
// These variables track which projects to rebuild.
$core = FALSE;
$contributions = array();

while (!fetch_projects($last_poll, $page, $core, $contributions)) {
  ++$page;
  echo "Fetching more! $page\n";
}

// Tracking variable for how many forks we have running.
$forks = 0;
if ($core) {
  $pid = pcntl_fork();
  if ($pid == -1) {
    die("oh noes! no fork!");
  }
  elseif ($pid) {
    // Parent
    $forks++;
  }
  else {
    import_directory($config_template, $repository, 'drupal', "$destination/project/drupal.git" );
    exit;
  }
}      

while (!empty($contributions)) {
  $project_dir = array_pop($contributions);
  $tmp = explode('/', $project_dir);
  $project = isset($tmp[2]) ? $tmp[2] : $tmp[1];

  $pid = pcntl_fork();

  if ($pid == -1) {
    die("oh noes! no fork!");
  }
  elseif ($pid) {
    // Parent
    $forks++;

    // If we've run out of headroom, wait for a process to finish.
    if ($forks >= $threads) {
      pcntl_wait($status);
      $forks--;
    }
  }
  else {
    import_directory($config_template, $repository, $project_dir, "$destination/project/$project.git" );
    exit;
  }
}

// Make sure all process finish before exiting.
while ($forks) { pcntl_wait($status); $forks--; }

/*******************************************************************************
 * Helper functions
 ******************************************************************************/

/**
 * Fetch a list of projects up until the given poll_date.
 */
function fetch_projects($last_poll, $page, &$core, &$contributions) {

  $url = 'http://drupal.org/cvs?rss=true';
  if ($page) {
    $url .= "&page=$page";
  }
  $xml = fetch_rss($url);

  foreach ($xml->channel->item as $item) {
    $matches = array();
    if (strpos($item->description, 'viewvc/drupal/drupal')) {
      $core = TRUE;
#      echo "Commit to core\n";
    }
    elseif (preg_match('#contributions/(modules|themes|theme-engines|profiles|translations|docs|tricks)/([^/]+)#', $item->description, $matches)) {
      // We don't really care about translations.
      if ($matches[1] == 'translations') continue;
      
      if ($matches[1] == 'docs' || $matches[1] == 'tricks') {
        $project = $matches[1];
        $contributions[$project] = "contributions/$project";
      }
      else {
        $project = $matches[2];
        $contributions[$project] = $matches[0];
      }
#      echo "Commit to $project: $item->link\n";
    }
    else {
      print_r($item);
      echo "woops... $item->link\n";
    }
    
    $commit_date = strtotime($item->pubDate);
    if ($commit_date < $last_poll) {
      return TRUE;
    }
  }

  return FALSE;
}

function fetch_rss($url) {
  $data = array();
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  $return = curl_exec($ch);
  curl_close($ch);
  $xml = simplexml_load_string($return);
  return $xml;
}
