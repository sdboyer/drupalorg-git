<?php
/**
 * @file shared.php
 *
 * Shared functionality needed by all our git hooks.
 */

// Provide a constant representing the null object name.
define('GIT_NULL_REV', '0000000000000000000000000000000000000000');

global $repo_json, $pusher_uid, $repo_id;
$repo_json = getenv('VERSION_CONTROL_VCS_AUTH_DATA');
$pusher_uid = getenv('VERSION_CONTROL_GIT_UID');
$repo_id = getenv('VERSION_CONTROL_GIT_REPO_ID');

// Queues we may want to communicate with.
global $queues;

$queues = array(
  'versioncontrol_git_repo_parsing' => array(
    'beanstalkd host' => 'localhost',
    'beanstalkd port' => 11300,
  ),
  'versioncontrol_repomgr' => array(
    'beanstalkd host' => 'localhost',
    'beanstalkd port' => 11300,
  ),
);

// Path to error log.
global $git_hook_error_log;
$git_hook_error_log = '/var/log/git/githook-err.log';
/**
 * Init our pheanstalk connection. We don't do this by default so that hooks
 * which don't need it can be as fast as possible.
 */
function _githooks_init_pheanstalk() {
  // We're assuming the pheanstalk library lives in php's include_path.
  require_once 'pheanstalk/pheanstalk_init.php';
}

function _githooks_enqueue_job($queue_name, $payload, $priority = 1024) {
  _githooks_init_pheanstalk();
  global $git_hook_error_log, $queues;
  try {
    $pheanstalk = new Pheanstalk($queues[$queue_name]['beanstalkd host'], $queues[$queue_name]['beanstalkd port']);
    // Build a stdClass item wrapper to make beanstalkd.module happy
    $item = new stdClass();
    $item->name = $queue_name;
    $item->data = $payload;
    $pheanstalk->useTube($queue_name);
    $pheanstalk->put(serialize($item), $priority);
  }
  catch (Exception $e) {
    if (file_exists($git_hook_error_log) && is_writable($git_hook_error_log)) {
      $message = date('Y-m-d:H-i-s') . ": Git 'post-recieve' error, queue '$queue_name': " . $e->getMessage() . print_r($data, TRUE);
      file_put_contents($git_hook_error_log, $message, FILE_APPEND);
    }
  }
}
